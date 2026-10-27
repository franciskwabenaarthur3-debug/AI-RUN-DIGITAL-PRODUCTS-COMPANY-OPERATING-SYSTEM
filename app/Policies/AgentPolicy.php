<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Agent $agent): bool
    {
        return $user->is_active;
    }

    /** Enabling, disabling and changing permissions is founder-only. */
    public function update(User $user, Agent $agent): bool
    {
        return $user->is_active && $user->isFounder();
    }
}
