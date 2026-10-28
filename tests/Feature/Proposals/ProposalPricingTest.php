<?php

namespace Tests\Feature\Proposals;

use App\Proposals\ProposalPricing;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProposalPricingTest extends TestCase
{
    private const LINES = [
        ['description' => 'Business website', 'quantity' => 1, 'unit_price_minor' => 150_000],
        ['description' => 'Extra page', 'quantity' => 3, 'unit_price_minor' => 12_550],
    ];

    private function refused(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail("Expected a pricing error on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_totals_are_computed_in_minor_units(): void
    {
        $price = ProposalPricing::calculate(self::LINES);

        $this->assertSame([150_000, 37_650], array_column($price->lines, 'line_total_minor'));
        $this->assertSame(187_650, $price->subtotalMinor);
        $this->assertSame(0, $price->discountMinor);
        $this->assertSame(187_650, $price->amountMinor);
    }

    public function test_percentage_and_fixed_discounts(): void
    {
        // 15% of 1876.50 = 281.475, rounded half up to 281.48.
        $percent = ProposalPricing::calculate(self::LINES, 'percent', 15, 'Returning customer');
        $this->assertSame(28_148, $percent->discountMinor);
        $this->assertSame(159_502, $percent->amountMinor);

        $fixed = ProposalPricing::calculate(self::LINES, 'amount', 10_000, 'Launch offer');
        $this->assertSame(177_650, $fixed->amountMinor);

        // The full allowed percentage, expressed as an amount, is accepted.
        $max = ProposalPricing::calculate(self::LINES, 'amount', intdiv(187_650 * 20 + 50, 100), 'Max');
        $this->assertSame(37_530, $max->discountMinor);
    }

    public function test_invalid_discounts_are_rejected(): void
    {
        $this->refused(fn () => ProposalPricing::calculate(self::LINES, 'percent', 21, 'Too much'), 'discount_value');
        $this->refused(fn () => ProposalPricing::calculate(self::LINES, 'percent', 0, 'Zero'), 'discount_value');
        $this->refused(fn () => ProposalPricing::calculate(self::LINES, 'percent', -5, 'Negative'), 'discount_value');
        $this->refused(fn () => ProposalPricing::calculate(self::LINES, 'amount', 37_531, 'Over 20%'), 'discount_value');
        $this->refused(fn () => ProposalPricing::calculate(self::LINES, 'amount', 0, 'Zero'), 'discount_value');
        $this->refused(fn () => ProposalPricing::calculate(self::LINES, 'amount', 1_000, ''), 'discount_reason');
        $this->refused(fn () => ProposalPricing::calculate(self::LINES, 'coupon', 5, 'x'), 'discount_type');
    }

    public function test_invalid_lines_are_rejected(): void
    {
        $this->refused(fn () => ProposalPricing::calculate([]), 'items');
        $this->refused(fn () => ProposalPricing::calculate([['description' => 'x', 'quantity' => 0, 'unit_price_minor' => 100]]), 'items.0.quantity');
        $this->refused(fn () => ProposalPricing::calculate([['description' => 'x', 'quantity' => 1, 'unit_price_minor' => -1]]), 'items.0.unit_price');
        $this->refused(fn () => ProposalPricing::calculate([['description' => ' ', 'quantity' => 1, 'unit_price_minor' => 100]]), 'items.0.description');
        $this->refused(fn () => ProposalPricing::calculate([['description' => 'Free', 'quantity' => 1, 'unit_price_minor' => 0]]), 'items');
        $this->refused(fn () => ProposalPricing::calculate(array_fill(0, 31, self::LINES[0])), 'items');
    }
}
