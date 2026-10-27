<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history entry for a lead.
 */
class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lead activities are immutable.'));
        static::deleting(fn () => throw new LogicException('Lead activities are immutable.'));
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** The human actor; only meaningful when actor_type is "user". */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actorName(): string
    {
        return match ($this->actor_type) {
            'user' => $this->actor?->name ?? 'User #'.$this->actor_id,
            'agent' => Agent::query()->whereKey($this->actor_id)->value('name') ?? 'Agent #'.$this->actor_id,
            default => $this->actor_type,
        };
    }
}
