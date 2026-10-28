<?php

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * Anyone active can see approval requests; only an active founder can
 * decide them. ApprovalService enforces the same rule on its own.
 */
class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, ApprovalRequest $approval): bool
    {
        return $user->is_active;
    }

    public function decide(User $user, ApprovalRequest $approval): bool
    {
        return $user->is_active && $user->isFounder();
    }
}
