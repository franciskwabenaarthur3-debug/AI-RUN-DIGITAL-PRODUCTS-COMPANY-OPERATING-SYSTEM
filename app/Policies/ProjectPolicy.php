<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Founders and operators run projects and record releases; only the
 * founder records a production deployment (after approving it, which is
 * also founder-only).
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canOperate();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->canOperate();
    }

    public function deploy(User $user, Project $project): bool
    {
        return $user->is_active && $user->isFounder();
    }
}
