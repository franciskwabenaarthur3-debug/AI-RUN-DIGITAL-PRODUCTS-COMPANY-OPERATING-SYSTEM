<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): Customer
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $customer = new Customer($attributes);
            $this->applyBilling($customer, $attributes, $actor);
            $customer->save();

            $this->audit->record('customer.created', $customer, actor: $actor);

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Customer $customer, array $attributes, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $attributes, $actor) {
            $customer->fill($attributes);
            $billingChanged = $this->applyBilling($customer, $attributes, $actor);
            $changed = array_values(array_diff(array_keys($customer->getDirty()), ['billing_details']));

            if ($changed === [] && ! $billingChanged) {
                return $customer;
            }

            $customer->save();

            // Field names only: values may be personal data.
            $this->audit->record('customer.updated', $customer, [
                'fields' => $changed,
                'billing_changed' => $billingChanged,
            ], $actor);

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyBilling(Customer $customer, array $attributes, User $actor): bool
    {
        if (! array_key_exists('billing_details', $attributes) || ! $actor->can('updateBilling', $customer)) {
            return false;
        }

        $new = trim((string) $attributes['billing_details']) ?: null;

        if ($new === $customer->billing_details) {
            return false;
        }

        $customer->billing_details = $new;

        return true;
    }
}
