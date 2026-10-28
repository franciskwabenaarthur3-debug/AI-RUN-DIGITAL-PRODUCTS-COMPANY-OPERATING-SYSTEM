<?php

namespace App\Models;

use App\Enums\MaintenanceTaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One maintenance visit. Written only by MaintenanceService. Once it is
 * completed, failed or cancelled its record (checklist, evidence) is kept
 * as it was.
 */
class MaintenanceTask extends Model
{
    public const RECORD = ['maintenance_plan_id', 'scheduled_at', 'checklist', 'health_detail', 'notes', 'evidence', 'completed_at', 'completed_by_user_id'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MaintenanceTaskStatus::class,
            'scheduled_at' => 'date',
            'checklist' => 'array',
            'health_detail' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $task) {
            $closed = ! MaintenanceTaskStatus::from($task->getRawOriginal('status'))->isOpen();

            if ($closed && ($task->isDirty(self::RECORD) || $task->isDirty('status'))) {
                throw new LogicException('A closed maintenance task cannot change.');
            }
        });
        static::deleting(fn () => throw new LogicException('Maintenance tasks are kept as service history.'));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(MaintenanceFinding::class)->orderBy('id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [MaintenanceTaskStatus::Scheduled, MaintenanceTaskStatus::InProgress]);
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate('scheduled_at', '<', today());
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->scheduled_at->isBefore(today());
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'scheduler' => 'Scheduled from the plan',
            'agent' => 'Scheduled by '.(Agent::query()->whereKey($this->source_id)->value('name') ?? 'an agent'),
            default => 'Scheduled by '.(User::query()->whereKey($this->source_id)->value('name') ?? 'a person'),
        };
    }
}
