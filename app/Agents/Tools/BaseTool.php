<?php

namespace App\Agents\Tools;

use App\Enums\RiskLevel;

abstract class BaseTool implements Tool
{
    public function resource(array $input): array
    {
        return [null, null];
    }

    public function riskLevel(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function rollbackPlan(array $input): string
    {
        return 'No external effect; correct or cancel the record in Company OS.';
    }
}
