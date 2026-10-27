<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Request;

/**
 * Writes append-only audit records for consequential operations.
 * Sensitive metadata keys are redacted before anything is persisted.
 */
class AuditLogger
{
    public const REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $resource = null,
        array $metadata = [],
        ?User $actor = null,
        string $actorType = 'user',
    ): AuditLog {
        $actor ??= auth()->user();

        return AuditLog::query()->create([
            'actor_type' => $actor || $actorType !== 'user' ? $actorType : 'system',
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'resource_type' => $resource ? $resource->getMorphClass() : null,
            'resource_id' => $resource?->getKey(),
            'correlation_id' => Context::get('correlation_id'),
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $sensitive = array_map('strtolower', config('company_os.redacted_keys'));

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive(strtolower($key), $sensitive)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    /**
     * @param  list<string>  $sensitive
     */
    private function isSensitive(string $key, array $sensitive): bool
    {
        foreach ($sensitive as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
