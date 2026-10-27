<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalPolicy;
use App\Enums\OperatingStatus;
use App\Http\Requests\Settings\UpdateCompanySettingsRequest;
use App\Models\CompanySetting;
use App\Services\CompanySettingsService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $settings = CompanySetting::current();
        $this->authorize('view', $settings);

        return view('settings.edit', [
            'settings' => $settings,
            'budget' => Money::fromMinor($settings->monthly_ai_budget_minor),
            'currencies' => config('company_os.currencies'),
            'approvalPolicies' => ApprovalPolicy::cases(),
            'operatingStatuses' => OperatingStatus::cases(),
            'canUpdate' => request()->user()->can('update', $settings),
        ]);
    }

    public function update(UpdateCompanySettingsRequest $request, CompanySettingsService $service): RedirectResponse
    {
        $service->update(CompanySetting::current(), $request->validated());

        return redirect()->route('settings.edit')->with('status', 'Settings saved.');
    }
}
