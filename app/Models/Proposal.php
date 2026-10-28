<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One version of a proposal. Written only by ProposalService. Content is
 * frozen once a version leaves draft; changes need a new version.
 */
class Proposal extends Model
{
    /** Columns that make up the proposal's content. */
    public const CONTENT = [
        'lead_id', 'customer_id', 'service_package_id', 'title', 'recipient_name', 'recipient_email',
        'introduction', 'scope_snapshot', 'subtotal_minor', 'discount_minor', 'discount_reason',
        'amount_minor', 'currency', 'payment_terms', 'expires_at', 'reference', 'version', 'project_id',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'scope_snapshot' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'amount_minor' => 'integer',
            'version' => 'integer',
            'expires_at' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $proposal) {
            $status = $proposal->getRawOriginal('status');
            $frozen = $status !== ProposalStatus::Draft->value;

            if ($frozen && $proposal->isDirty(self::CONTENT)) {
                throw new LogicException('A proposal\'s content cannot change after it leaves draft. Create a new version.');
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProposalEvent::class)->orderBy('id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /** The existing project a change request adds work to. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The project created when this proposal was accepted. */
    public function createdProject(): HasOne
    {
        return $this->hasOne(Project::class, 'proposal_id');
    }

    public function isChangeRequest(): bool
    {
        return $this->project_id !== null;
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function nextVersion(): HasOne
    {
        return $this->hasOne(self::class, 'previous_version_id');
    }

    public function creatorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function creatorAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'created_by_agent_id');
    }

    /** All versions with this reference, oldest first. */
    public function versions(): Builder
    {
        return self::query()->where('reference', $this->reference)->orderBy('version');
    }

    public function isLatest(): bool
    {
        return ! self::query()->where('previous_version_id', $this->id)->exists();
    }

    public function isEditable(): bool
    {
        return $this->status === ProposalStatus::Draft;
    }

    public function recipientLabel(): string
    {
        return $this->lead?->company_name ?? $this->customer?->company_name ?? 'Unknown recipient';
    }

    public function creatorName(): string
    {
        return $this->creatorUser?->name ?? $this->creatorAgent?->name ?? 'System';
    }

    public function label(): string
    {
        return "{$this->reference} v{$this->version}";
    }
}
