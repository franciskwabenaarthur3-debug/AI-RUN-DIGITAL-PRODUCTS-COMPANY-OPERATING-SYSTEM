<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\ModelUsage;

/**
 * Compares this month's estimated AI spend with the company budget. The
 * hard stop is in TaskRunner (paid runs are refused when they could
 * exceed the budget); this raises the warning at the configured share and
 * records each threshold once per month in the audit log.
 */
class AiBudgetMonitor
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{level: 'warning'|'exhausted', spent_minor: int, budget_minor: int, percent: int}|null
     */
    public function status(): ?array
    {
        $settings = CompanySetting::current();
        $budget = $settings->monthly_ai_budget_minor;
        $spent = ModelUsage::companySpentThisMonthMinor();

        if ($budget <= 0 || $spent <= 0) {
            return null;
        }

        $percent = intdiv($spent * 100, $budget);

        if ($spent >= $budget) {
            $level = 'exhausted';
        } elseif ($percent >= $settings->ai_budget_alert_percent) {
            $level = 'warning';
        } else {
            return null;
        }

        return ['level' => $level, 'spent_minor' => $spent, 'budget_minor' => $budget, 'percent' => $percent];
    }

    public function check(): void
    {
        $status = $this->status();

        if (! $status) {
            return;
        }

        $action = $status['level'] === 'exhausted' ? 'ai.budget_exhausted' : 'ai.budget_threshold_reached';
        $already = AuditLog::query()->where('action', $action)->where('created_at', '>=', now()->startOfMonth())->exists();

        if (! $already) {
            $this->audit->record($action, CompanySetting::current(), [
                'spent_minor' => $status['spent_minor'],
                'budget_minor' => $status['budget_minor'],
                'percent' => $status['percent'],
                'currency' => config('company_os.ai.currency'),
            ], actorType: 'system');
        }
    }
}
