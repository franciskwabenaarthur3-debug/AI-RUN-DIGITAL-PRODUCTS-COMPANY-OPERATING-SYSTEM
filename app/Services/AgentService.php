<?php

namespace App\Services;

use App\Agents\ToolCatalog;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Founder-controlled changes to agents. Agents themselves can never call
 * these: there is no tool for changing status or permissions.
 */
class AgentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function setStatus(Agent $agent, AgentStatus $status, User $actor): Agent
    {
        if ($agent->status === $status) {
            return $agent;
        }

        $from = $agent->status;
        $agent->status = $status;
        $agent->save();

        $this->audit->record('agent.status_changed', $agent, ['from' => $from->value, 'to' => $status->value], $actor);

        return $agent;
    }

    /**
     * Founder changes to the runtime, model and limits. Every changed
     * field is audited with its old and new value.
     *
     * @param  array<string, mixed>  $config
     */
    public function configure(Agent $agent, array $config, User $actor): Agent
    {
        return DB::transaction(function () use ($agent, $config, $actor) {
            $agent = Agent::query()->lockForUpdate()->findOrFail($agent->id);
            $agent->fill(array_intersect_key($config, array_flip(self::CONFIGURABLE)));

            $changes = [];
            foreach ($agent->getDirty() as $field => $new) {
                $changes[$field] = ['from' => $agent->getRawOriginal($field), 'to' => $new];
            }

            if ($changes !== []) {
                $agent->save();
                $this->audit->record('agent.configured', $agent, ['changes' => $changes], $actor);
            }

            return $agent;
        });
    }

    public const CONFIGURABLE = [
        'runtime', 'model_key', 'max_steps', 'max_retries', 'timeout_seconds',
        'max_tokens_per_run', 'max_tool_calls_per_run', 'monthly_budget_minor',
    ];

    public function setPermission(Agent $agent, string $tool, bool $allowed, User $actor): void
    {
        if (! ToolCatalog::exists($tool)) {
            throw new InvalidArgumentException("Unknown tool: {$tool}");
        }

        DB::transaction(function () use ($agent, $tool, $allowed, $actor) {
            $permission = $agent->permissions()->firstOrNew(['tool_name' => $tool, 'resource_scope' => '*']);

            if ($permission->exists && $permission->allowed === $allowed) {
                return;
            }

            $permission->allowed = $allowed;
            $permission->save();

            $this->audit->record($allowed ? 'agent.permission_granted' : 'agent.permission_revoked', $agent, ['tool' => $tool], $actor);
        });
    }
}
