<?php

namespace App\Enums;

enum ResearchStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Complete = 'complete';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
