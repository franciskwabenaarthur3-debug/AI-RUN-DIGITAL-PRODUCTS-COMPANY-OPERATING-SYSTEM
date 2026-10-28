<?php

namespace App\Proposals;

final class PriceBreakdown
{
    /**
     * @param  list<array{description: string, quantity: int, unit_price_minor: int, line_total_minor: int}>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $subtotalMinor,
        public readonly int $discountMinor,
        public readonly int $amountMinor,
    ) {}
}
