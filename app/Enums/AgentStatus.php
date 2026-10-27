<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
