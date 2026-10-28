<?php

namespace App\Enums;

/**
 * draft → (production only) pending_approval → approved → deployed →
 * verified or failed_verification → rolled_back. Staging releases skip
 * approval. Checks are recorded only in draft.
 */
enum ReleaseStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Deployed = 'deployed';
    case Verified = 'verified';
    case FailedVerification = 'failed_verification';
    case RolledBack = 'rolled_back';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
