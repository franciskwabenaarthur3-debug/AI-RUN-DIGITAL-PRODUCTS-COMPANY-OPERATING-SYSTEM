<?php

namespace App\Http\Controllers;

use App\Agents\ToolCatalog;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\CompanySetting;
use App\Services\AgentService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Agent::class);

        $agents = Agent::query()
            ->withCount([
                'permissions as tools_count' => fn ($q) => $q->where('allowed', true),
                'runs',
                'runs as failed_runs_count' => fn ($q) => $q->where('status', AgentRunStatus::Failed),
            ])
            ->orderBy('id')
            ->get();

        return view('agents.index', [
            'agents' => $agents,
            'currency' => CompanySetting::current()->default_currency,
        ]);
    }

    public function show(Agent $agent): View
    {
        $this->authorize('view', $agent);

        $granted = $agent->permissions()->where('allowed', true)->pluck('tool_name')->all();
        $usage = $agent->usage()->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(input_tokens + output_tokens), 0) as tokens, COALESCE(SUM(estimated_cost_minor), 0) as estimated, SUM(actual_cost_minor) as actual')
            ->first();
        $currency = CompanySetting::current()->default_currency;

        return view('agents.show', [
            'agent' => $agent,
            'tools' => ToolCatalog::TOOLS,
            'granted' => $granted,
            'runs' => $agent->runs()->with('task:id,title')->latest('id')->paginate(15),
            'usage' => $usage,
            'spent' => Money::format($agent->spentThisMonthMinor(), $currency),
            'budget' => Money::format($agent->monthly_budget_minor, $currency),
        ]);
    }

    public function updateStatus(Request $request, Agent $agent, AgentService $agents): RedirectResponse
    {
        $this->authorize('update', $agent);
        $data = $request->validate([
            'status' => ['required', Rule::enum(AgentStatus::class)],
            'confirm' => ['accepted'],
        ]);

        $agents->setStatus($agent, AgentStatus::from($data['status']), $request->user());

        return back()->with('status', "{$agent->name} is now {$data['status']}.");
    }

    public function updatePermissions(Request $request, Agent $agent, AgentService $agents): RedirectResponse
    {
        $this->authorize('update', $agent);
        $data = $request->validate([
            'tools' => ['nullable', 'array'],
            'tools.*' => ['string', Rule::in(ToolCatalog::names())],
        ]);

        $grant = $data['tools'] ?? [];
        foreach (ToolCatalog::names() as $tool) {
            $agents->setPermission($agent, $tool, in_array($tool, $grant, true), $request->user());
        }

        return back()->with('status', 'Permissions saved.');
    }
}
