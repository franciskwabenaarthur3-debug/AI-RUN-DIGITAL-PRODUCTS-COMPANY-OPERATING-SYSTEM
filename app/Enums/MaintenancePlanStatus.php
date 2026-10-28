<?php

namespace App\Enums;

enum MaintenancePlanStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    /** The term ended without a renewal. */
    case Ended = 'ended';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
