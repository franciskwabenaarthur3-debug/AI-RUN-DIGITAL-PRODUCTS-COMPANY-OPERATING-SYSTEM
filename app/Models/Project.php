<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Written only by ProjectService and ReleaseService. */
class Project extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'budget_minor' => 'integer',
            'handover_checklist' => 'array',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(ProjectDeliverable::class)->orderBy('id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(ProjectRelease::class)->latest('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProjectEvent::class)->orderBy('id');
    }

    public function handoverComplete(): bool
    {
        return collect($this->handover_checklist)->every(fn ($item) => $item['done'] ?? false);
    }

    public function overdueMilestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->overdue();
    }

    public function customerName(): string
    {
        return $this->customer?->company_name ?? $this->proposal?->recipientLabel() ?? 'No customer linked';
    }
}
