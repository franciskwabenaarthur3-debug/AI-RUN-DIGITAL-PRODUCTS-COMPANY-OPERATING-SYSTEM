<?php

namespace App\Ai;

use RuntimeException;

/**
 * A provider failure in provider-neutral terms. Codes: rate_limited,
 * overloaded, timeout, connection, auth, rejected, provider_error.
 */
class ProviderError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable,
    ) {
        parent::__construct($message);
    }
}
