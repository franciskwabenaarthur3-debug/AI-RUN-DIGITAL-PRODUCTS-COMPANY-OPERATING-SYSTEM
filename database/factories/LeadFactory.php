<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+233 20 000 0000',
            'website' => 'https://'.fake()->unique()->domainWord().'.example',
            'source' => 'manual',
            'source_reference' => 'Entered manually',
            'research_status' => 'pending',
            'qualification_score' => null,
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->afterMaking(fn (Lead $lead) => $lead->status = $status);
    }

    public function optedOut(): static
    {
        return $this->afterMaking(function (Lead $lead) {
            $lead->status = LeadStatus::Unsubscribed;
            $lead->do_not_contact = true;
            $lead->opted_out_at = now();
            $lead->opt_out_reason = 'Asked to be removed';
        });
    }
}
