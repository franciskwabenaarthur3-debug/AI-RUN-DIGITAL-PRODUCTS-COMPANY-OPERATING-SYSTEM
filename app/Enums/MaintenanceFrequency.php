<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum MaintenanceFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
    }

    public function after(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->addMonthsNoOverflow($this->months());
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
