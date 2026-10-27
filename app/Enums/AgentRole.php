<?php

namespace App\Enums;

enum AgentRole: string
{
    case Orchestrator = 'orchestrator';
    case Research = 'research';
    case Sales = 'sales';
    case Engineering = 'engineering';
    case Marketing = 'marketing';
    case CustomerSuccess = 'customer_success';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Orchestrator => 'CEO / Orchestrator',
            self::Engineering => 'Product & Engineering',
            self::CustomerSuccess => 'Customer Success',
            self::Finance => 'Finance & Operations',
            default => ucfirst($this->value),
        };
    }
}
