<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An operational alert. Raised only by AlertService; one per condition (dedupe_key). */
class Alert extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'severity' => AlertSeverity::class,
            'acknowledged_at' => 'datetime',
        ];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('acknowledged_at');
    }

    /** Where the alert points, for a link. */
    public function subjectUrl(): ?string
    {
        if (! $this->subject_id) {
            return null;
        }

        return match ($this->subject_type) {
            'maintenance_task' => route('maintenance.tasks.show', $this->subject_id),
            'maintenance_plan' => route('maintenance.plans.show', $this->subject_id),
            'project_release' => route('releases.show', $this->subject_id),
            'approval_request' => route('approvals.show', $this->subject_id),
            'task' => route('tasks.show', $this->subject_id),
            default => null,
        };
    }
}
