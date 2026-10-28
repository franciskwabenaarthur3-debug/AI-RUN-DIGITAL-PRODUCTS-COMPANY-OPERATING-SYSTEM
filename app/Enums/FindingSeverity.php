<?php

namespace App\Enums;

enum FindingSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function raisesAlert(): bool
    {
        return in_array($this, [self::High, self::Critical], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
