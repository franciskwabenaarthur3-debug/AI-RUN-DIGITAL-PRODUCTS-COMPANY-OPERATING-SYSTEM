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
    ) {}

    /**
     * @param  array{company_name: string, default_currency: string, timezone: string, approval_policy: string, monthly_ai_budget: string, operating_status: string}  $input
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

            // Lifting the emergency pause resumes tasks left waiting in the queue.
            if (isset($changes['operating_status']) && ! $settings->isPaused()) {
                $this->tasks->dispatchQueued();
            }

            return $settings;
        });
    }
}
