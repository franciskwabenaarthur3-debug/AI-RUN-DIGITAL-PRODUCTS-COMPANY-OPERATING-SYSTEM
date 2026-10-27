<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPermission extends Model
{
    protected $fillable = ['tool_name', 'resource_scope', 'allowed', 'limits'];

    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
            'limits' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
