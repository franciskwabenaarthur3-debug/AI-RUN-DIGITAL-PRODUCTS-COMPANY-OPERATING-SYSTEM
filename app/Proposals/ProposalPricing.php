<?php

namespace App\Proposals;

use Illuminate\Validation\ValidationException;

/**
 * All proposal arithmetic, in integer minor units. Nothing else computes
 * a line total, subtotal, discount or amount, and no model output ever
 * supplies a price.
 */
final class ProposalPricing
{
    public const MAX_QUANTITY = 1000;

    public const MAX_UNIT_PRICE_MINOR = 100_000_000_00; // 100 million in major units

    /**
     * @param  list<array{description: string, quantity: int, unit_price_minor: int}>  $lines
     * @param  'none'|'amount'|'percent'  $discountType
     * @param  int  $discountValue  minor units for 'amount', whole percent for 'percent'
     *
     * @throws ValidationException
     */
    public static function calculate(array $lines, string $discountType = 'none', int $discountValue = 0, ?string $discountReason = null): PriceBreakdown
    {
        $errors = [];
        $maxItems = (int) config('company_os.proposals.max_items');
        $maxPercent = (int) config('company_os.proposals.max_discount_percent');

        if ($lines === []) {
            $errors['items'] = 'A proposal needs at least one priced line.';
        }
        if (count($lines) > $maxItems) {
            $errors['items'] = "A proposal can have at most {$maxItems} lines.";
        }

        $priced = [];
        $subtotal = 0;
        foreach (array_values($lines) as $i => $line) {
            $description = trim((string) $line['description']);
            $quantity = $line['quantity'];
            $unit = $line['unit_price_minor'];

            if ($description === '') {
                $errors["items.{$i}.description"] = 'Each line needs a description.';
            }
            if (! is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                $errors["items.{$i}.quantity"] = 'Quantity must be a whole number from 1 to '.self::MAX_QUANTITY.'.';

                continue;
            }
            if (! is_int($unit) || $unit < 0 || $unit > self::MAX_UNIT_PRICE_MINOR) {
                $errors["items.{$i}.unit_price"] = 'Unit price must be between 0 and 100,000,000.';

                continue;
            }

            $total = $quantity * $unit;
            $subtotal += $total;
            $priced[] = ['description' => $description, 'quantity' => $quantity, 'unit_price_minor' => $unit, 'line_total_minor' => $total];
        }

        $discount = 0;
        if ($errors === []) {
            switch ($discountType) {
                case 'none':
                    break;
                case 'percent':
                    if ($discountValue < 1 || $discountValue > $maxPercent) {
                        $errors['discount_value'] = "A percentage discount must be from 1 to {$maxPercent}%.";
                        break;
                    }
                    // Rounded half up to the nearest minor unit.
                    $discount = intdiv($subtotal * $discountValue + 50, 100);
                    break;
                case 'amount':
                    if ($discountValue < 1) {
                        $errors['discount_value'] = 'A fixed discount must be more than zero.';
                        break;
                    }
                    if ($discountValue > intdiv($subtotal * $maxPercent + 50, 100)) {
                        $errors['discount_value'] = "A discount cannot exceed {$maxPercent}% of the subtotal.";
                        break;
                    }
                    $discount = $discountValue;
                    break;
                default:
                    $errors['discount_type'] = 'Unknown discount type.';
            }

            if ($discount > 0 && blank($discountReason)) {
                $errors['discount_reason'] = 'Give a reason for the discount.';
            }
            if ($subtotal - $discount <= 0) {
                $errors['items'] = 'The proposal total must be more than zero.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new PriceBreakdown($priced, $subtotal, $discount, $subtotal - $discount);
    }
}
