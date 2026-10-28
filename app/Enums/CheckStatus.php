<?php

namespace App\Enums;

enum CheckStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    /** Only optional checks can be skipped. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
