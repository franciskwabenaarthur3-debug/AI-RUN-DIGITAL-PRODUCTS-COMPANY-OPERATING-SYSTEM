<?php

namespace App\Models;

use App\Enums\AgentRole;
use App\Enums\AgentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A logical agent: its identity, limits and permissions. Status and
 * permissions change only through AgentService (founder only).
 */
class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'role',
        'description',
        'instructions_version',
        'runtime',
        'model_key',
        'configuration',
        'max_steps',
        'max_retries',
        'timeout_seconds',
        'max_tokens_per_run',
        'monthly_budget_minor',
        'review_required',
    ];

    protected $attributes = [
        'status' => 'disabled',
    ];

    protected function casts(): array
    {
        return [
            'role' => AgentRole::class,
            'status' => AgentStatus::class,
            'configuration' => 'array',
            'max_steps' => 'integer',
            'max_retries' => 'integer',
            'timeout_seconds' => 'integer',
            'max_tokens_per_run' => 'integer',
            'monthly_budget_minor' => 'integer',
            'review_required' => 'boolean',
        ];
    }

    public function isEnabled(): bool
    {
        return $this->status === AgentStatus::Enabled;
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(AgentPermission::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(ModelUsage::class);
    }

    /**
     * Whether the agent has been granted a tool. Unknown or ungranted tools
     * are denied; the model never decides its own permissions.
     */
    public function mayUseTool(string $tool, string $scope = '*'): bool
    {
        return $this->permissions()
            ->where('tool_name', $tool)
            ->whereIn('resource_scope', array_unique([$scope, '*']))
            ->where('allowed', true)
            ->exists();
    }

    public function spentThisMonthMinor(): int
    {
        return (int) $this->usage()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE(SUM(COALESCE(actual_cost_minor, estimated_cost_minor)), 0) as total')
            ->value('total');
    }
}
