<?php

namespace Tests\Feature\Proposals;

use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ProposalService;

trait InteractsWithProposals
{
    protected User $founder;

    protected User $operator;

    protected ServicePackage $package;

    protected Lead $lead;

    protected function setUpProposals(): void
    {
        $this->founder = User::factory()->founder()->create();
        $this->operator = User::factory()->operator()->create();
        $this->package = ServicePackage::factory()->create(['name' => 'Business Website', 'base_price_minor' => 150_000, 'currency' => 'GHS', 'estimated_days' => 14]);
        $this->lead = Lead::factory()->create(['company_name' => 'Acme Bakery', 'contact_name' => 'Ama Mensah', 'email' => 'ama@acme-bakery.example']);
    }

    protected function proposals(): ProposalService
    {
        return app(ProposalService::class);
    }

    protected function draft(array $data = []): Proposal
    {
        return $this->proposals()->create($data + [
            'lead_id' => $this->lead->id,
            'service_package_id' => $this->package->id,
            'title' => 'New website for Acme Bakery',
            'introduction' => 'Thanks for your time on Tuesday.',
            'extra_items' => [['description' => 'Extra page', 'quantity' => 2, 'unit_price_minor' => 10_000]],
        ], $this->operator);
    }

    protected function submitted(array $data = []): Proposal
    {
        return $this->proposals()->submit($this->draft($data), $this->operator)->fresh();
    }

    protected function approved(array $data = []): Proposal
    {
        $proposal = $this->submitted($data);
        app(ApprovalService::class)->decide($proposal->approvalRequest, $this->founder, true);

        return $proposal->fresh();
    }

    protected function sentProposal(array $data = []): Proposal
    {
        return $this->proposals()->send($this->approved($data), 'manual', $this->founder, 'Emailed from my inbox on 28 Oct')->fresh();
    }
}
