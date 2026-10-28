<?php

namespace App\Models;

use App\Enums\MaintenanceFrequency;
use App\Enums\MaintenancePlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A maintenance agreement with a customer. Written only by MaintenanceService. */
class MaintenancePlan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'billing_frequency' => MaintenanceFrequency::class,
            'service_interval' => MaintenanceFrequency::class,
            'status' => MaintenancePlanStatus::class,
            'amount_minor' => 'integer',
            'checklist' => 'array',
            'next_service_at' => 'date',
            'term_ends_at' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class)->orderByDesc('scheduled_at');
    }

    /** The site maintenance visits check: the project's production URL. */
    public function siteUrl(): ?string
    {
        return $this->project?->production_url;
    }

    public function renewalDue(): bool
    {
        return $this->status === MaintenancePlanStatus::Active
            && $this->term_ends_at->lte(today()->addDays((int) config('company_os.maintenance.renewal_reminder_days')));
    }
}
