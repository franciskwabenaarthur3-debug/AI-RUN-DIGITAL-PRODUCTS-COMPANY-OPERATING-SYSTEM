<?php

namespace App\Agents;

use App\Enums\AgentRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Structured output every agent run must produce. Output is untrusted:
 * anything that does not match is rejected and the run fails.
 *
 * `kind` deliberately has no value meaning "action completed": agents
 * produce drafts, analyses, recommendations and reports, never proof that
 * something happened in the outside world.
 */
final class OutputSchema
{
    public const KINDS = ['draft', 'analysis', 'recommendation', 'report'];

    private const ALLOWED_KEYS = ['kind', 'summary', 'items', 'sources', 'assumptions', 'recommendations'];

    /**
     * @return array<string, mixed> the validated output
     *
     * @throws ValidationException
     */
    public static function validate(AgentRole $role, mixed $output): array
    {
        if (! is_array($output) || array_is_list($output) && $output !== []) {
            throw ValidationException::withMessages(['output' => 'Output must be an object.']);
        }

        $unknown = array_diff(array_keys($output), self::ALLOWED_KEYS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['output' => 'Unexpected output fields: '.implode(', ', $unknown)]);
        }

        $rules = [
            'kind' => ['required', 'string', 'in:'.implode(',', self::KINDS)],
            'summary' => ['required', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'max:50'],
            'items.*' => ['string', 'max:2000'],
            'recommendations' => ['sometimes', 'array', 'max:20'],
            'recommendations.*' => ['string', 'max:2000'],
            'sources' => ['sometimes', 'array', 'max:50'],
            'sources.*.reference' => ['required', 'string', 'max:2048'],
            'sources.*.retrieved_at' => ['required', 'date'],
            'assumptions' => ['sometimes', 'array', 'max:20'],
            'assumptions.*' => ['string', 'max:1000'],
        ];

        // Research claims need a source or must be marked as assumptions.
        if ($role === AgentRole::Research) {
            $rules['sources'] = ['required_without:assumptions', 'array', 'max:50'];
            $rules['assumptions'] = ['required_without:sources', 'array', 'max:20'];
        }

        return Validator::make($output, $rules)->validate();
    }
}
