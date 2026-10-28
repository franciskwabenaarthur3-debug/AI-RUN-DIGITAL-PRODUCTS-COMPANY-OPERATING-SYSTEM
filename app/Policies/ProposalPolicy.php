<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;

/**
 * Founders and operators prepare proposals and record answers; only the
 * founder sends (after approving, which is also founder-only).
 */
class ProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canOperate();
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return $user->canOperate();
    }

    public function send(User $user, Proposal $proposal): bool
    {
        return $user->is_active && $user->isFounder();
    }
}
