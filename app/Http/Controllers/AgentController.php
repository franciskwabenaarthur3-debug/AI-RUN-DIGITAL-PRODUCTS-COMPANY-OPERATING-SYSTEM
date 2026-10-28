<?php

namespace App\Http\Controllers;

use App\Agents\AgentInstructions;
use App\Agents\Runtime\RuntimeRegistry;
use App\Agents\ToolCatalog;
use App\Ai\ModelCatalog;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Models\Agent;
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
            'currency' => config('company_os.ai.currency'),
        ]);
    }

    public function show(Agent $agent): View
    {
        $this->authorize('view', $agent);

        $granted = $agent->permissions()->where('allowed', true)->pluck('tool_name')->all();
        $usage = $agent->usage()->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(input_tokens + output_tokens), 0) as tokens, COALESCE(SUM(estimated_cost_minor), 0) as estimated, SUM(actual_cost_minor) as actual')
            ->first();
        $currency = config('company_os.ai.currency');

        return view('agents.show', [
            'agent' => $agent,
            'tools' => ToolCatalog::TOOLS,
            'granted' => $granted,
            'runs' => $agent->runs()->with('task:id,title')->latest('id')->paginate(15),
            'usage' => $usage,
            'spent' => Money::format($agent->spentThisMonthMinor(), $currency),
            'budget' => Money::format($agent->monthly_budget_minor, $currency),
            'runtimes' => app(RuntimeRegistry::class)->names(),
            'models' => app(ModelCatalog::class)->options(),
            'model' => app(ModelCatalog::class)->find($agent->model_key),
            'hasInstructions' => AgentInstructions::load($agent) !== null,
            'instructionVersions' => AgentInstructions::versions($agent),
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

    public function updateConfiguration(Request $request, Agent $agent, AgentService $agents, RuntimeRegistry $runtimes, ModelCatalog $models): RedirectResponse
    {
        $this->authorize('update', $agent);
        $data = $request->validate([
            'runtime' => ['required', Rule::in($runtimes->names())],
            'model_key' => ['nullable', Rule::in($models->keys()), 'required_if:runtime,llm'],
            'instructions_version' => ['sometimes', 'string', Rule::in(AgentInstructions::versions($agent))],
            'max_steps' => ['required', 'integer', 'between:1,50'],
            'max_retries' => ['required', 'integer', 'between:0,5'],
            'timeout_seconds' => ['required', 'integer', 'between:10,900'],
            'max_tokens_per_run' => ['required', 'integer', 'between:1000,1000000'],
            'max_tool_calls_per_run' => ['required', 'integer', 'between:0,100'],
            'monthly_budget' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
        ], ['monthly_budget.regex' => 'Enter a non-negative amount with at most two decimals.']);

        $data['monthly_budget_minor'] = Money::toMinor($data['monthly_budget']);
        $agents->configure($agent, $data, $request->user());

        return back()->with('status', 'Configuration saved.');
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
