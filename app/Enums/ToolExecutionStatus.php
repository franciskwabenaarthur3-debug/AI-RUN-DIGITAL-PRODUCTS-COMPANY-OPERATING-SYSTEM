<?php

namespace App\Enums;

enum ToolExecutionStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Denied = 'denied';
    case AwaitingApproval = 'awaiting_approval';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
