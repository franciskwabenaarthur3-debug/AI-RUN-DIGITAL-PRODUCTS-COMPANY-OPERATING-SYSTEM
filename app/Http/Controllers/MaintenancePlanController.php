<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Enums\MaintenancePlanStatus;
use App\Exceptions\InvalidTransition;
use App\Http\Requests\Maintenance\MaintenancePlanRequest;
use App\Models\Customer;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use App\Models\Project;
use App\Services\MaintenanceService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaintenancePlanController extends Controller
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function index(): View
    {
        $this->authorize('viewAny', MaintenancePlan::class);

        return view('maintenance.index', [
            'plans' => MaintenancePlan::query()->with('customer:id,company_name')->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('next_service_at')->get(),
            'due' => MaintenanceTask::query()->open()->whereDate('scheduled_at', '<=', today()->addDays(14))->with('plan.customer:id,company_name')->orderBy('scheduled_at')->get(),
            'recent' => MaintenanceTask::query()->whereNotIn('status', ['scheduled', 'in_progress'])->with('plan.customer:id,company_name')->latest('completed_at')->limit(10)->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', MaintenancePlan::class);
        $project = $request->integer('project_id') ? Project::query()->find($request->integer('project_id')) : null;

        return view('maintenance.form', [
            'plan' => new MaintenancePlan([
                'customer_id' => $project?->customer_id ?? ($request->integer('customer_id') ?: null),
                'project_id' => $project?->id,
                'plan_name' => $project ? "Care plan: {$project->name}" : null,
                'currency' => $project?->currency ?? config('company_os.currencies')[0],
                'billing_frequency' => 'monthly',
                'service_interval' => 'monthly',
                'checklist' => config('company_os.maintenance.default_checklist'),
                'next_service_at' => today(),
                'term_ends_at' => today()->addYear(),
            ]),
            'customers' => Customer::query()->where('status', CustomerStatus::Active)->orderBy('company_name')->get(['id', 'company_name']),
            'projects' => Project::query()->whereNotNull('customer_id')->orderBy('name')->get(['id', 'name', 'customer_id']),
        ]);
    }

    public function store(MaintenancePlanRequest $request): RedirectResponse
    {
        try {
            $plan = $this->maintenance->createPlan($request->planData(), $request->user());
        } catch (InvalidTransition $e) {
            return back()->withInput()->withErrors(['plan' => $e->getMessage()]);
        }

        return redirect()->route('maintenance.plans.show', $plan)->with('status', 'Maintenance plan created. Visits are scheduled automatically before each service date.');
    }

    public function show(MaintenancePlan $plan): View
    {
        $this->authorize('view', $plan);

        return view('maintenance.plan', [
            'plan' => $plan->load(['customer', 'project', 'tasks.findings']),
        ]);
    }

    public function edit(MaintenancePlan $plan): View
    {
        $this->authorize('update', $plan);

        return view('maintenance.form', [
            'plan' => $plan,
            'customers' => collect(),
            'projects' => Project::query()->where('customer_id', $plan->customer_id)->orderBy('name')->get(['id', 'name', 'customer_id']),
        ]);
    }

    public function update(MaintenancePlanRequest $request, MaintenancePlan $plan): RedirectResponse
    {
        return $this->attempt($plan, function () use ($request, $plan) {
            $this->maintenance->updatePlan($plan, $request->planData(), $request->user());

            return 'Plan saved.';
        });
    }

    public function status(Request $request, MaintenancePlan $plan): RedirectResponse
    {
        $this->authorize('update', $plan);
        $data = $request->validate([
            'status' => ['required', Rule::enum(MaintenancePlanStatus::class)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->attempt($plan, function () use ($request, $plan, $data) {
            $this->maintenance->setPlanStatus($plan, MaintenancePlanStatus::from($data['status']), $data['reason'], $request->user());

            return 'Plan status changed.';
        });
    }

    public function renew(Request $request, MaintenancePlan $plan): RedirectResponse
    {
        $this->authorize('update', $plan);
        $data = $request->validate([
            'term_ends_at' => ['required', 'date', 'after:today'],
            'evidence' => ['required', 'string', 'max:2000'],
        ]);

        return $this->attempt($plan, function () use ($request, $plan, $data) {
            $this->maintenance->renew($plan, Carbon::parse($data['term_ends_at']), $data['evidence'], $request->user());

            return 'Renewal recorded.';
        });
    }

    public function schedule(Request $request, MaintenancePlan $plan): RedirectResponse
    {
        $this->authorize('operate', $plan);
        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->attempt($plan, function () use ($request, $plan, $data) {
            $this->maintenance->scheduleTask($plan, Carbon::parse($data['date']), $request->user(), $data['title'] ?? null);

            return 'Visit scheduled.';
        });
    }

    private function attempt(MaintenancePlan $plan, Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (InvalidTransition $e) {
            return redirect()->route('maintenance.plans.show', $plan)->withErrors(['plan' => $e->getMessage()]);
        }

        return redirect()->route('maintenance.plans.show', $plan)->with('status', $message);
    }
}
