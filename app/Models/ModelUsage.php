<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'model_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_minor' => 'integer',
            'actual_cost_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public static function companySpentThisMonthMinor(): int
    {
        return (int) static::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE(SUM(COALESCE(actual_cost_minor, estimated_cost_minor)), 0) as total')
            ->value('total');
    }
}
