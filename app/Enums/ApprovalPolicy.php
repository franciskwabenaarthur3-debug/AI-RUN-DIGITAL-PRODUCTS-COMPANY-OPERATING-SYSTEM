<?php

namespace App\Enums;

enum ApprovalPolicy: string
{
    /** Every Level 2 action requires explicit founder approval. */
    case Strict = 'strict';

    public function label(): string
    {
        return match ($this) {
            self::Strict => 'Strict (founder approves every Level 2 action)',
        };
    }
}
