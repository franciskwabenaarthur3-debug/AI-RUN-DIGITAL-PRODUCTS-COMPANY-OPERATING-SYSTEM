<?php

namespace App\Policies;

use App\Models\CompanySetting;
use App\Models\User;

class CompanySettingPolicy
{
    public function view(User $user, CompanySetting $settings): bool
    {
        return $user->is_active;
    }

    public function update(User $user, CompanySetting $settings): bool
    {
        return $user->is_active && $user->isFounder();
    }
}
