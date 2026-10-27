<?php

namespace App\Governance;

/**
 * Canonical JSON and hashing for approval payloads, so the same content
 * always produces the same hash regardless of key order.
 */
final class Payload
{
    public static function canonical(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function hash(string $actionType, ?string $resourceType, ?int $resourceId, array $payload): string
    {
        return hash('sha256', self::canonical([
            'action' => $actionType,
            'resource' => [$resourceType, $resourceId],
            'payload' => $payload,
        ]));
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sort(...), $value);
    }
}
