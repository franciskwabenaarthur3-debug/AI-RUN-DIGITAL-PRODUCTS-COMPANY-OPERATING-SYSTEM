<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\ServicePackage;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $settings = CompanySetting::current();
        $user = $request->user();

        return view('dashboard.index', [
            'settings' => $settings,
            'activePackages' => ServicePackage::query()->where('is_active', true)->count(),
            'settingsReviewed' => AuditLog::query()->where('action', 'settings.updated')->exists(),
            'aiBudget' => Money::format($settings->monthly_ai_budget_minor, $settings->default_currency),
            'recentAudit' => $user->can('viewAny', AuditLog::class)
                ? AuditLog::query()->latest('id')->limit(8)->get()
                : null,
        ]);
    }
}
