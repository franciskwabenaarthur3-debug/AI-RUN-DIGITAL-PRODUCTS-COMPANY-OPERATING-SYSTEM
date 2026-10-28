<?php

namespace App\Http\Requests\Projects;

use App\Enums\ReleaseEnvironment;
use App\Rules\LinkWithoutCredentials;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'environment' => ['required', Rule::enum(ReleaseEnvironment::class)],
            'version' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'commit_reference' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'target_url' => ['nullable', 'url:http,https', 'max:255', new LinkWithoutCredentials],
            'release_notes' => ['required', 'string', 'max:5000'],
            'rollback_plan' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'version.regex' => 'Use letters, digits, dots, dashes or underscores, e.g. 1.0.0.',
            'commit_reference.regex' => 'Use a commit hash, tag or branch name.',
        ];
    }
}
