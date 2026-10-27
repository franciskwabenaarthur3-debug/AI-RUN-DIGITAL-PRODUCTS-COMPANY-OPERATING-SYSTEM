<?php

namespace App\Enums;

enum UserRole: string
{
    /** Full control, including settings, approvals and permission changes. */
    case Founder = 'founder';

    /** Day-to-day operation of records; cannot change governance settings. */
    case Operator = 'operator';

    /** Read-only access. */
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
