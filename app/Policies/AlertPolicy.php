<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

class AlertPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function acknowledge(User $user, Alert $alert): bool
    {
        return $user->canOperate();
    }
}
