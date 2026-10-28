<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only history of a project. */
class ProjectEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Project events cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Project events cannot be deleted.'));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actorName(): string
    {
        return match ($this->actor_type) {
            'user' => User::query()->whereKey($this->actor_id)->value('name') ?? 'Deleted user',
            'agent' => Agent::query()->whereKey($this->actor_id)->value('name') ?? 'Deleted agent',
            default => 'System',
        };
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->event_type));
    }
}
