<?php

namespace App\Models;

use App\Enums\AgentRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'output' => 'array',
            'usage_metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'deadline_at' => 'datetime',
            'attempt' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(ModelUsage::class);
    }
}
