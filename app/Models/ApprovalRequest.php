<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\RiskLevel;
use App\Governance\Payload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Written only by ApprovalService. The snapshot and hash never change
 * after creation; any change to the action needs a new request.
 */
class ApprovalRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'risk_level' => RiskLevel::class,
            'payload_snapshot' => 'array',
            'evidence' => 'array',
            'execution_result' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'execution_started_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            if ($request->isDirty(['payload_snapshot', 'payload_hash', 'action_type', 'resource_type', 'resource_id'])) {
                throw new \LogicException('An approval request\'s action and payload cannot be changed.');
            }
        });
    }

    public function decision(): HasOne
    {
        return $this->hasOne(ApprovalDecision::class);
    }

    public function requestingAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'requested_by_agent_id');
    }

    public function requestingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Whether the stored snapshot still matches its hash (detects tampering). */
    public function snapshotIntact(): bool
    {
        return hash_equals($this->payload_hash, Payload::hash($this->action_type, $this->resource_type, $this->resource_id, $this->payload_snapshot));
    }

    public function requesterName(): string
    {
        return $this->requestingAgent?->name ?? $this->requestingUser?->name ?? 'System';
    }
}
