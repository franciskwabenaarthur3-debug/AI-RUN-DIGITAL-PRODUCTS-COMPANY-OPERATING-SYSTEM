<?php

namespace App\Jobs;

use App\Agents\Tools\ToolGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the tool call behind an approved request. Not retried by the
 * queue: the approval's atomic claim makes duplicate deliveries harmless,
 * and a failed action needs a new approval.
 */
class ExecuteApprovedAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $approvalRequestId) {}

    public function handle(ToolGateway $gateway): void
    {
        $gateway->executeApproved($this->approvalRequestId);
    }
}
