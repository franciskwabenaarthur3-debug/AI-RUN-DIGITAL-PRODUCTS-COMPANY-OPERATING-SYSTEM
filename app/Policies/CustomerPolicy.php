<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canOperate();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->canOperate();
    }

    public function viewBilling(User $user, Customer $customer): bool
    {
        return $user->is_active && $user->isFounder();
    }

    public function updateBilling(User $user, Customer $customer): bool
    {
        return $user->is_active && $user->isFounder();
    }
}
