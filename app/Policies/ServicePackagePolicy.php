<?php

namespace App\Policies;

use App\Models\ServicePackage;
use App\Models\User;

/**
 * Package scope and prices are maintained by the founder only.
 */
class ServicePackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, ServicePackage $package): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isFounder();
    }

    public function update(User $user, ServicePackage $package): bool
    {
        return $user->is_active && $user->isFounder();
    }
}
