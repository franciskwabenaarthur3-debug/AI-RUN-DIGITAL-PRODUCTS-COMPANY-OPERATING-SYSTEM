<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CompanySettingsService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TaskService $tasks,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array{company_name: string, default_currency: string, timezone: string, approval_policy: string, monthly_ai_budget: string, operating_status: string, ai_budget_alert_percent?: int|string}  $input
     */
    public function update(CompanySetting $settings, array $input): CompanySetting
    {
        return DB::transaction(function () use ($settings, $input) {
            $settings->fill([
                'company_name' => $input['company_name'],
                'default_currency' => $input['default_currency'],
                'timezone' => $input['timezone'],
                'approval_policy' => $input['approval_policy'],
                'monthly_ai_budget_minor' => Money::toMinor($input['monthly_ai_budget']),
                'ai_budget_alert_percent' => (int) ($input['ai_budget_alert_percent'] ?? $settings->ai_budget_alert_percent),
                'operating_status' => $input['operating_status'],
            ]);

            $changes = [];
            foreach ($settings->getDirty() as $field => $new) {
                $changes[$field] = ['from' => $settings->getRawOriginal($field), 'to' => $new];
            }

            if ($changes === []) {
                return $settings;
            }

            $settings->save();
            $this->audit->record('settings.updated', $settings, ['changes' => $changes]);

            // Lifting the emergency pause resumes queued tasks and approved tool calls.
            if (isset($changes['operating_status']) && ! $settings->isPaused()) {
                $this->tasks->dispatchQueued();
                $this->approvals->dispatchApproved();
            }

            return $settings;
        });
    }
}
