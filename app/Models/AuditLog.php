<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit record. Updates and deletes are refused at the model
 * level, and on PostgreSQL also by a database trigger.
 */
class AuditLog extends Model
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
        static::updating(fn () => throw new LogicException('Audit records are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit records are immutable.'));
    }
}
