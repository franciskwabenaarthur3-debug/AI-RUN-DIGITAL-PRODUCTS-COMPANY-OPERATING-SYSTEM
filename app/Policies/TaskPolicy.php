<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Task $task): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canOperate();
    }

    public function operate(User $user, Task $task): bool
    {
        return $user->canOperate();
    }
}
