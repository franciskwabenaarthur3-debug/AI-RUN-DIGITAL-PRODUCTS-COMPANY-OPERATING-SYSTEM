<?php

namespace App\Policies;

use App\Models\MaintenancePlan;
use App\Models\User;

/**
 * Everyone active can read plans and visits. Only the founder creates
 * plans or changes their price, schedule or term; founders and operators
 * carry out visits.
 */
class MaintenancePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, MaintenancePlan $plan): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isFounder();
    }

    public function update(User $user, MaintenancePlan $plan): bool
    {
        return $user->is_active && $user->isFounder();
    }

    public function operate(User $user, MaintenancePlan $plan): bool
    {
        return $user->canOperate();
    }
}
