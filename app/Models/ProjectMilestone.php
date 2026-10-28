<?php

namespace App\Models;

use App\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectMilestone extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            'position' => 'integer',
            'due_at' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'milestone_id')->orderBy('id');
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', '!=', MilestoneStatus::Completed)->whereDate('due_at', '<', today());
    }

    public function isOverdue(): bool
    {
        return $this->status !== MilestoneStatus::Completed && $this->due_at?->isBefore(today());
    }
}
