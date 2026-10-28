<?php

namespace App\Enums;

/**
 * open → resolved or wont_fix. A risky finding goes open →
 * approval_requested → approved (→ resolved once the change is recorded)
 * or rejected.
 */
enum FindingStatus: string
{
    case Open = 'open';
    case ApprovalRequested = 'approval_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Resolved = 'resolved';
    case WontFix = 'wont_fix';

    public function isClosed(): bool
    {
        return in_array($this, [self::Rejected, self::Resolved, self::WontFix], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::WontFix => "Won't fix",
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }
}
