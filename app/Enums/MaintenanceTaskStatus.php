<?php

namespace App\Enums;

/**
 * scheduled → in_progress → completed or failed; scheduled or in_progress
 * → cancelled. Closed tasks keep their checklist, findings and evidence.
 */
enum MaintenanceTaskStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::InProgress], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
