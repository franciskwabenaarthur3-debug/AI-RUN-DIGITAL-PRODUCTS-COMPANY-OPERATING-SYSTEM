<?php

namespace App\Proposals;

use App\Enums\CustomerStatus;
use App\Models\Proposal;
use Illuminate\Validation\ValidationException;

/**
 * Checks a proposal version must pass before it is submitted for approval
 * and again right before it is sent. Returns problems as plain sentences.
 */
final class ProposalChecks
{
    /**
     * @return list<string>
     */
    public static function problems(Proposal $proposal): array
    {
        $problems = [];
        $package = $proposal->servicePackage;

        if (! $package || ! $package->is_active) {
            $problems[] = 'The service package is inactive or missing.';
        } elseif ($package->currency !== $proposal->currency) {
            $problems[] = "The package is priced in {$package->currency} but the proposal is in {$proposal->currency}.";
        }

        if ($proposal->lead_id === null && $proposal->customer_id === null) {
            $problems[] = 'The proposal has no lead or customer.';
        }
        if ($lead = $proposal->lead) {
            if (! $lead->canBeContacted()) {
                $problems[] = 'The lead has opted out, so it cannot receive a proposal.';
            }
        }
        if ($customer = $proposal->customer) {
            if ($customer->status !== CustomerStatus::Active) {
                $problems[] = 'The customer is not active.';
            }
        }

        if (blank($proposal->recipient_email) || ! filter_var($proposal->recipient_email, FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'A valid recipient email is required.';
        }

        $scope = $proposal->scope_snapshot['scope'] ?? [];
        if ($scope === []) {
            $problems[] = 'The scope is empty.';
        }
        if (blank($proposal->payment_terms)) {
            $problems[] = 'Payment terms are required.';
        }
        if ($proposal->expires_at === null || ! $proposal->expires_at->isFuture()) {
            $problems[] = 'The validity date must be after today.';
        }

        // The stored totals must match a fresh calculation of the stored lines.
        $items = $proposal->items()->get();
        try {
            $lines = $items->map(fn ($i) => ['description' => $i->description, 'quantity' => $i->quantity, 'unit_price_minor' => $i->unit_price_minor])->all();
            $type = $proposal->discount_minor > 0 ? 'amount' : 'none';
            $fresh = ProposalPricing::calculate($lines, $type, $proposal->discount_minor, $proposal->discount_reason);
            $lineTotalsMatch = $items->every(fn ($i) => $i->line_total_minor === $i->quantity * $i->unit_price_minor);

            if (! $lineTotalsMatch || $fresh->subtotalMinor !== $proposal->subtotal_minor || $fresh->amountMinor !== $proposal->amount_minor) {
                $problems[] = 'The stored totals do not match the line items.';
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $problems[] = $messages[0];
            }
        }

        return array_values(array_unique($problems));
    }
}
