<?php

namespace App\Console\Commands;

use App\Services\ApprovalService;
use Illuminate\Console\Command;

class ExpireApprovals extends Command
{
    protected $signature = 'company-os:expire-approvals';

    protected $description = 'Expire approval requests past their deadline and flag executions whose outcome is unknown';

    public function handle(ApprovalService $approvals): int
    {
        [$expired, $flagged] = $approvals->sweep();

        $this->info("Expired {$expired} approval request(s); flagged {$flagged} for reconciliation.");

        return self::SUCCESS;
    }
}
