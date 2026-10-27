<?php

namespace App\Governance;

use RuntimeException;

/**
 * Raised when an action may not execute under its approval. The code is
 * stable: missing, pending, rejected, expired, invalidated, already_executed,
 * in_progress, payload_mismatch, failed, needs_reconciliation, not_decidable,
 * wrong_approver.
 */
class ApprovalRefused extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
