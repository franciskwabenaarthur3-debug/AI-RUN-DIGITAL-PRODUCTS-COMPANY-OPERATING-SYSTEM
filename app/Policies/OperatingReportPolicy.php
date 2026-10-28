<?php

namespace App\Policies;

use App\Models\OperatingReport;
use App\Models\User;

/** Reports include costs and contracted values, so viewers do not see them. */
class OperatingReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canOperate();
    }

    public function view(User $user, OperatingReport $report): bool
    {
        return $user->canOperate();
    }
}
