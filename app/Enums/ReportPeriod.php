<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum ReportPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * The period containing $date, as [first day, day after the last].
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function bounds(Carbon $date): array
    {
        $start = match ($this) {
            self::Daily => $date->copy()->startOfDay(),
            self::Weekly => $date->copy()->startOfWeek(Carbon::MONDAY),
            self::Monthly => $date->copy()->startOfMonth(),
        };
        $end = match ($this) {
            self::Daily => $start->copy()->addDay(),
            self::Weekly => $start->copy()->addWeek(),
            self::Monthly => $start->copy()->addMonth(),
        };

        return [$start, $end];
    }

    /** The last period that has fully ended before $now. */
    public function previous(Carbon $now): Carbon
    {
        return match ($this) {
            self::Daily => $now->copy()->subDay(),
            self::Weekly => $now->copy()->subWeek(),
            self::Monthly => $now->copy()->startOfMonth()->subMonth(),
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
