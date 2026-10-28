<?php

namespace App\Enums;

enum ReleaseEnvironment: string
{
    case Staging = 'staging';
    case Production = 'production';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
