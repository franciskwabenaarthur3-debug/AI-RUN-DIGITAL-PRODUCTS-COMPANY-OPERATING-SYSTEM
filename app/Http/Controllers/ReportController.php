<?php

namespace App\Http\Controllers;

use App\Enums\ReportPeriod;
use App\Models\Agent;
use App\Models\ModelUsage;
use App\Models\OperatingReport;
use App\Services\OperatingReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, OperatingReportService $reports): View
    {
        $this->authorize('viewAny', OperatingReport::class);
        $data = $request->validate(['period' => ['nullable', Rule::enum(ReportPeriod::class)]]);
        $period = ReportPeriod::from($data['period'] ?? 'weekly');

        return view('reports.index', [
            'period' => $period,
            'report' => $reports->build($period, now()),
            'live' => true,
            'stored' => OperatingReport::query()->latest('period_start')->latest('id')->limit(30)->get(['id', 'period', 'period_start', 'period_end', 'generated_at']),
        ]);
    }

    public function show(OperatingReport $report): View
    {
        $this->authorize('view', $report);

        return view('reports.show', ['stored' => $report, 'report' => $report->data]);
    }

    /** AI usage cost by month, agent and model, from recorded usage. */
    public function costs(Request $request): View
    {
        $this->authorize('viewAny', OperatingReport::class);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = isset($data['month']) ? Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth() : now()->startOfMonth();
        $cost = 'COALESCE(SUM(COALESCE(actual_cost_minor, estimated_cost_minor)), 0)';

        $months = collect(range(5, 0))->map(function ($ago) use ($cost) {
            $start = now()->startOfMonth()->subMonths($ago);
            $row = ModelUsage::query()->where('created_at', '>=', $start)->where('created_at', '<', $start->copy()->addMonth())
                ->selectRaw("{$cost} as total, COUNT(*) as calls, COALESCE(SUM(input_tokens + output_tokens), 0) as tokens, SUM(CASE WHEN actual_cost_minor IS NULL THEN 1 ELSE 0 END) as estimated")->first();

            return ['month' => $start, 'total' => (int) $row->total, 'calls' => (int) $row->calls, 'tokens' => (int) $row->tokens, 'estimated' => (int) $row->estimated > 0];
        });

        $inMonth = ModelUsage::query()->where('created_at', '>=', $month)->where('created_at', '<', $month->copy()->addMonth());

        return view('reports.costs', [
            'month' => $month,
            'months' => $months,
            'currency' => config('company_os.ai.currency'),
            'byAgent' => (clone $inMonth)->selectRaw("agent_id, {$cost} as total, COUNT(*) as calls")->groupBy('agent_id')->get()
                ->map(fn ($r) => ['agent' => Agent::query()->whereKey($r->agent_id)->value('name') ?? "Agent #{$r->agent_id}", 'total' => (int) $r->total, 'calls' => (int) $r->calls]),
            'byModel' => (clone $inMonth)->selectRaw("model, {$cost} as total, COUNT(*) as calls, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens")->groupBy('model')->get(),
        ]);
    }
}
