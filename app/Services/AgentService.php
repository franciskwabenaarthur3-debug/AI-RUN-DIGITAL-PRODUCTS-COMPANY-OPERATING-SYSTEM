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
