<?php

namespace App\Agents\Tools;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Task;

/** Who is calling a tool and from which run. */
final class ToolContext
{
    public function __construct(
        public readonly Agent $agent,
        public readonly ?AgentRun $run = null,
        public readonly ?Task $task = null,
        public readonly ?ApprovalRequest $approval = null,
    ) {}

    public function withApproval(ApprovalRequest $approval): self
    {
        return new self($this->agent, $this->run, $this->task, $approval);
    }
}
