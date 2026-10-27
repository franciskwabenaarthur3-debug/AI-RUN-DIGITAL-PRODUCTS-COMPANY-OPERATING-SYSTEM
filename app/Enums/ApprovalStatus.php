<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    /** The payload changed after approval, so the approval no longer applies. */
    case Invalidated = 'invalidated';
    /** Claimed for execution; the side effect may be in progress. */
    case Executing = 'executing';
    case Executed = 'executed';
    case Failed = 'failed';
    /** Execution started but its outcome is unknown; check the external system by hand. */
    case NeedsReconciliation = 'needs_reconciliation';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
