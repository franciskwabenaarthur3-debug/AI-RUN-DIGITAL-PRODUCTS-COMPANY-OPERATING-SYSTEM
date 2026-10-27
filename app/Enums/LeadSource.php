<?php

namespace App\Enums;

enum LeadSource: string
{
    case Manual = 'manual';
    case Referral = 'referral';
    case Inbound = 'inbound';
    case Research = 'research';
    case Event = 'event';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
