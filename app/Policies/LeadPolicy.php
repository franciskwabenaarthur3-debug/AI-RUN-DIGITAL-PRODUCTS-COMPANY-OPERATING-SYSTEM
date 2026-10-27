<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canOperate();
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->canOperate();
    }

    public function changeStatus(User $user, Lead $lead): bool
    {
        return $user->canOperate();
    }

    public function optOut(User $user, Lead $lead): bool
    {
        return $user->canOperate();
    }

    public function convert(User $user, Lead $lead): bool
    {
        return $user->canOperate();
    }
}
