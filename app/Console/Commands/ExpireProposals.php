<?php

namespace App\Console\Commands;

use App\Services\ProposalService;
use Illuminate\Console\Command;

class ExpireProposals extends Command
{
    protected $signature = 'company-os:expire-proposals';

    protected $description = 'Mark sent proposals past their validity date as expired';

    public function handle(ProposalService $proposals): int
    {
        $this->info("Expired {$proposals->expireSent()} proposal(s).");

        return self::SUCCESS;
    }
}
