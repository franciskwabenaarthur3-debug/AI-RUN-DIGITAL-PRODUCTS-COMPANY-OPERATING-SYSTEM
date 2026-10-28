<?php

namespace App\Services;

use App\Enums\AgentRunStatus;
use App\Enums\AlertSeverity;
use App\Enums\ApprovalStatus;
use App\Enums\MaintenancePlanStatus;
use App\Enums\ReleaseStatus;
use App\Models\AgentRun;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use App\Models\ProjectRelease;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * In-app operational alerts. Each condition has a dedupe key, so raising
 * it again (from the sweep or a repeated event) does nothing. Alerts are
 * shown in Company OS only; nothing is emailed or sent anywhere.
 */
class AlertService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function raise(string $type, AlertSeverity $severity, string $title, ?string $detail, string $dedupeKey, ?string $subjectType = null, ?int $subjectId = null): Alert
    {
        if ($existing = Alert::query()->where('dedupe_key', $dedupeKey)->first()) {
            return $existing;
        }

        try {
            $alert = Alert::query()->create([
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'detail' => $detail,
                'dedupe_key' => $dedupeKey,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Alert::query()->where('dedupe_key', $dedupeKey)->sole();
        }

        $this->audit->record('alert.raised', $alert, ['type' => $type, 'severity' => $severity->value], actorType: 'system');

        return $alert;
    }

    public function acknowledge(Alert $alert, User $actor): Alert
    {
        if ($alert->acknowledged_at) {
            return $alert;
        }

        $alert->acknowledged_at = now();
        $alert->acknowledged_by_user_id = $actor->id;
        $alert->save();
        $this->audit->record('alert.acknowledged', $alert, ['type' => $alert->type], $actor);

        return $alert;
    }

    /**
     * Raise alerts for conditions found in the records. Returns how many
     * new alerts were raised.
     */
    public function sweep(): int
    {
        $before = Alert::query()->count();

        foreach (MaintenanceTask::query()->overdue()->with('plan.customer')->get() as $task) {
            $this->raise('maintenance_overdue', AlertSeverity::Warning, "Maintenance overdue: {$task->title}",
                "Scheduled for {$task->scheduled_at->format('M j, Y')} ({$task->plan->customer->company_name}).",
                "maintenance_overdue:{$task->id}", 'maintenance_task', $task->id);
        }

        $window = today()->addDays((int) config('company_os.maintenance.renewal_reminder_days'));
        foreach (MaintenancePlan::query()->where('status', MaintenancePlanStatus::Active)->whereDate('term_ends_at', '<=', $window)->with('customer')->get() as $plan) {
            $this->raise('renewal_due', $plan->term_ends_at->isPast() ? AlertSeverity::Warning : AlertSeverity::Info,
                "Renewal due: {$plan->plan_name} for {$plan->customer->company_name}",
                "The term ends {$plan->term_ends_at->format('M j, Y')}. Agree a renewal with the client, then record it on the plan.",
                "renewal_due:{$plan->id}:{$plan->term_ends_at->toDateString()}", 'maintenance_plan', $plan->id);
        }

        foreach (ProjectRelease::query()->where('status', ReleaseStatus::FailedVerification)->with('project')->get() as $release) {
            $this->raise('release_failed', $release->isProduction() ? AlertSeverity::Critical : AlertSeverity::Warning,
                "{$release->label()} of {$release->project->name} failed its health check",
                $release->health_detail['error'] ?? null,
                "release_failed:{$release->id}:{$release->health_checked_at?->timestamp}", 'project_release', $release->id);
        }

        foreach (ApprovalRequest::query()->where('status', ApprovalStatus::NeedsReconciliation)->get() as $approval) {
            $this->raise('approval_reconcile', AlertSeverity::Critical, "Approval #{$approval->id} needs reconciliation",
                "{$approval->action_type} started but its outcome was not recorded. Check whether it happened before doing anything else.",
                "approval_reconcile:{$approval->id}", 'approval_request', $approval->id);
        }

        $failed = AgentRun::query()->where('status', AgentRunStatus::Failed)->where('created_at', '>=', today())->count();
        if ($failed > 0) {
            $this->raise('agent_runs_failed', AlertSeverity::Warning, 'Agent runs failed today',
                'See the Tasks page for errors. More failures today do not raise another alert.',
                'agent_runs_failed:'.today()->toDateString());
        }

        if ($budget = app(AiBudgetMonitor::class)->status()) {
            $this->raise('ai_budget', $budget['level'] === 'exhausted' ? AlertSeverity::Critical : AlertSeverity::Warning,
                $budget['level'] === 'exhausted' ? 'AI budget used up: paid agent runs are refused' : "AI budget {$budget['percent']}% used",
                null, "ai_budget:{$budget['level']}:".now()->format('Y-m'));
        }

        return Alert::query()->count() - $before;
    }
}
