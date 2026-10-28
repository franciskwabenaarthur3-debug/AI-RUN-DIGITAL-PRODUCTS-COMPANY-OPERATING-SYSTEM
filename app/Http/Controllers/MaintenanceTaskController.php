<?php

namespace App\Http\Controllers;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Exceptions\InvalidTransition;
use App\Models\CompanySetting;
use App\Models\MaintenanceFinding;
use App\Models\MaintenanceTask;
use App\Services\MaintenanceService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaintenanceTaskController extends Controller
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function show(MaintenanceTask $task): View
    {
        $this->authorize('view', $task->plan);

        return view('maintenance.task', ['task' => $task->load(['plan.customer', 'plan.project', 'findings.approvalRequest', 'findings.recorder:id,name', 'completedBy:id,name'])]);
    }

    /** The service report for the client: what was checked, found and done. */
    public function report(MaintenanceTask $task): View
    {
        $this->authorize('view', $task->plan);

        return view('maintenance.report', [
            'task' => $task->load(['plan.customer', 'plan.project', 'findings', 'completedBy:id,name']),
            'company' => CompanySetting::current(),
        ]);
    }

    public function start(Request $request, MaintenanceTask $task): RedirectResponse
    {
        return $this->attempt($request, $task, function () use ($request, $task) {
            $this->maintenance->start($task, $request->user());

            return 'Visit started.';
        });
    }

    public function checklist(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $data = $request->validate([
            'item' => ['required', 'integer', 'min:0'],
            'result' => ['required', Rule::in(['ok', 'issue', 'not_applicable', 'pending'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt($request, $task, function () use ($request, $task, $data) {
            $this->maintenance->recordChecklistItem($task, (int) $data['item'], $data['result'], $data['note'] ?? null, $request->user());

            return 'Checklist updated.';
        });
    }

    public function healthCheck(Request $request, MaintenanceTask $task): RedirectResponse
    {
        return $this->attempt($request, $task, function () use ($request, $task) {
            $checked = $this->maintenance->runHealthCheck($task, $request->user());

            return $checked->health_detail['healthy']
                ? "Healthy: HTTP {$checked->health_detail['http_status']}."
                : 'Not healthy: '.$checked->health_detail['error'].' A high-severity finding was recorded.';
        });
    }

    public function finding(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $data = $request->validate([
            'severity' => ['required', Rule::enum(FindingSeverity::class)],
            'description' => ['required', 'string', 'max:2000'],
            'recommendation' => ['nullable', 'string', 'max:2000'],
            'risky' => ['nullable', 'boolean'],
        ]);

        return $this->attempt($request, $task, function () use ($request, $task, $data) {
            $this->maintenance->addFinding($task, $data + ['risky' => (bool) ($data['risky'] ?? false)], $request->user());

            return 'Finding recorded.';
        });
    }

    public function resolveFinding(Request $request, MaintenanceTask $task, MaintenanceFinding $finding): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([FindingStatus::Resolved->value, FindingStatus::WontFix->value])],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->attempt($request, $task, function () use ($request, $finding, $data) {
            $this->maintenance->resolveFinding($finding, FindingStatus::from($data['status']), $data['note'], $request->user());

            return 'Finding closed.';
        });
    }

    public function requestChange(Request $request, MaintenanceTask $task, MaintenanceFinding $finding): RedirectResponse
    {
        $data = $request->validate([
            'change_plan' => ['required', 'string', 'max:3000'],
            'rollback_plan' => ['required', 'string', 'max:3000'],
        ]);

        return $this->attempt($request, $task, function () use ($request, $finding, $data) {
            $this->maintenance->requestChangeApproval($finding, $data['change_plan'], $data['rollback_plan'], $request->user());

            return 'Sent to the founder for approval. Do not change the live site until it is approved.';
        });
    }

    public function recordChange(Request $request, MaintenanceTask $task, MaintenanceFinding $finding): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        return $this->attempt($request, $task, function () use ($request, $finding, $data) {
            $this->maintenance->recordChange($finding, $data['note'], $request->user());

            return 'Change recorded and finding resolved.';
        });
    }

    public function complete(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $data = $request->validate(['evidence' => ['required', 'string', 'max:5000']]);

        return $this->attempt($request, $task, function () use ($request, $task, $data) {
            $this->maintenance->complete($task, $data['evidence'], $request->user());

            return 'Visit completed. The service report is ready.';
        });
    }

    public function fail(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt($request, $task, function () use ($request, $task, $data) {
            $this->maintenance->fail($task, $data['reason'], $request->user());

            return 'Visit marked failed and an alert raised.';
        });
    }

    public function cancel(Request $request, MaintenanceTask $task): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt($request, $task, function () use ($request, $task, $data) {
            $this->maintenance->cancel($task, $data['reason'], $request->user());

            return 'Visit cancelled.';
        });
    }

    private function attempt(Request $request, MaintenanceTask $task, Closure $action): RedirectResponse
    {
        $this->authorize('operate', $task->plan);

        try {
            $message = $action();
        } catch (InvalidTransition $e) {
            return redirect()->route('maintenance.tasks.show', $task)->withErrors(['task' => $e->getMessage()]);
        }

        return redirect()->route('maintenance.tasks.show', $task)->with('status', $message);
    }
}
