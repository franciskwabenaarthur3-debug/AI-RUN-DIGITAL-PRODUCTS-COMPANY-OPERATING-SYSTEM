<?php

namespace App\Models;

use App\Enums\ReportPeriod;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A stored snapshot of an operating report for a finished period. Never changed. */
class OperatingReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period' => ReportPeriod::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'data' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stored reports cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Stored reports cannot be deleted.'));
    }

    public function label(): string
    {
        return match ($this->period) {
            ReportPeriod::Daily => $this->period_start->format('D j M Y'),
            ReportPeriod::Weekly => 'Week of '.$this->period_start->format('j M Y'),
            ReportPeriod::Monthly => $this->period_start->format('F Y'),
        };
    }
}
