<?php

namespace App\Enums;

enum OperatingStatus: string
{
    case Active = 'active';

    /** Emergency pause: agents and external actions must not start new work. */
    case Paused = 'paused';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
