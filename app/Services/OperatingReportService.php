<?php

namespace App\Services;

use App\Enums\AgentRunStatus;
use App\Enums\ApprovalStatus;
use App\Enums\FindingStatus;
use App\Enums\LeadStatus;
use App\Enums\MaintenancePlanStatus;
use App\Enums\MaintenanceTaskStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReleaseStatus;
use App\Enums\ReportPeriod;
use App\Enums\TaskStatus;
use App\Enums\ToolExecutionStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Alert;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\Lead;
use App\Models\MaintenanceFinding;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use App\Models\ModelUsage;
use App\Models\OperatingReport;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\ProjectMilestone;
use App\Models\ProjectRelease;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\ToolExecution;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Operating reports computed from Company OS records only. Nothing is
 * estimated or invented: where Company OS does not record something (paid
 * invoices, expenses, support tickets), the report says so. Recommended
 * priorities come from fixed rules over these numbers and are advisory.
 */
class OperatingReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(ReportPeriod $period, Carbon $date): array
    {
        [$from, $to] = $period->bounds($date);
        $in = fn ($query, string $column = 'created_at') => $query->where($column, '>=', $from)->where($column, '<', $to);
        $percent = fn (int $part, int $whole) => $whole > 0 ? (int) round($part * 100 / $whole) : null;

        $leadsCreated = $in(Lead::query())->count();
        $leadsQualified = $in(Lead::query())->whereIn('status', [LeadStatus::Qualified, LeadStatus::OutreachDrafted, LeadStatus::Contacted, LeadStatus::Replied, LeadStatus::Converted])->count();

        $sendDecisions = $in(ApprovalDecision::query())->whereHas('request', fn ($q) => $q->where('action_type', ProposalService::SEND_ACTION));
        $approved = (clone $sendDecisions)->where('decision', 'approved')->count();
        $rejected = (clone $sendDecisions)->where('decision', 'rejected')->count();
        $answered = $in(Proposal::query(), 'responded_at')->get(['status', 'amount_minor', 'currency']);
        $accepted = $answered->where('status', ProposalStatus::Accepted);
        $lost = $answered->whereIn('status', [ProposalStatus::Declined, ProposalStatus::Expired])->count();

        $delivered = $in(Project::query(), 'delivered_at')->get(['id', 'start_date', 'due_date', 'delivered_at']);
        $onTime = $delivered->filter(fn ($p) => $p->due_date && $p->delivered_at->toDateString() <= $p->due_date->toDateString())->count();
        $active = [ProjectStatus::Delivered, ProjectStatus::Closed, ProjectStatus::Cancelled];

        $runs = $in(AgentRun::query())->get(['agent_id', 'status']);
        $usage = $in(ModelUsage::query())->get(['agent_id', 'model', 'estimated_cost_minor', 'actual_cost_minor']);
        $cost = fn ($rows) => (int) $rows->sum(fn ($u) => $u->actual_cost_minor ?? $u->estimated_cost_minor);
        $agentNames = Agent::query()->pluck('name', 'id');
        $completedTasks = $in(Task::query(), 'completed_at')->whereNotNull('agent_id')->count();

        $data = [
            'period' => $period->value,
            'from' => $from->toDateString(),
            'to' => $to->copy()->subDay()->toDateString(),
            'sales' => [
                'leads_created' => $leadsCreated,
                'leads_qualified' => $leadsQualified,
                'qualified_rate_percent' => $percent($leadsQualified, $leadsCreated),
                'proposals_awaiting_approval' => Proposal::query()->where('status', ProposalStatus::PendingApproval)->count(),
                'proposals_sent' => $in(Proposal::query(), 'sent_at')->count(),
                'proposal_approval_rate_percent' => $percent($approved, $approved + $rejected),
                'proposals_accepted' => $accepted->count(),
                'proposal_conversion_rate_percent' => $percent($accepted->count(), $accepted->count() + $lost),
                // Accepted is agreed work, not money received.
                'accepted_value_minor' => $accepted->groupBy('currency')->map(fn ($rows) => (int) $rows->sum('amount_minor'))->all(),
            ],
            'projects' => [
                'active' => Project::query()->whereNotIn('status', $active)->count(),
                'created' => $in(Project::query())->count(),
                'delivered' => $delivered->count(),
                'on_time_rate_percent' => $percent($onTime, $delivered->count()),
                'average_delivery_days' => ($days = $delivered->filter(fn ($p) => $p->start_date)->avg(fn ($p) => $p->start_date->diffInDays($p->delivered_at))) !== null ? (int) round($days) : null,
                'changes_requested' => $in(ProjectEvent::query())->where('event_type', 'status_changed')->get(['metadata'])->filter(fn ($e) => ($e->metadata['to'] ?? null) === ProjectStatus::ChangesRequested->value)->count(),
                'overdue_milestones' => ProjectMilestone::query()->overdue()->whereHas('project', fn ($q) => $q->whereNotIn('status', $active))->count(),
                'releases_failed_verification' => ProjectRelease::query()->where('status', ReleaseStatus::FailedVerification)->count(),
            ],
            'tasks' => [
                'blocked' => Task::query()->where('status', TaskStatus::Blocked)->count(),
                'awaiting_review' => Task::query()->where('status', TaskStatus::AwaitingReview)->count(),
                'agent_runs' => $runs->count(),
                'agent_runs_failed' => $runs->where('status', AgentRunStatus::Failed)->count(),
                'agent_success_rate_percent' => $percent($runs->where('status', AgentRunStatus::Succeeded)->count(), $runs->whereIn('status', [AgentRunStatus::Succeeded, AgentRunStatus::Failed])->count()),
                'by_agent' => $runs->groupBy('agent_id')->map(fn ($rows, $id) => [
                    'agent' => $agentNames[$id] ?? "Agent #{$id}",
                    'runs' => $rows->count(),
                    'failed' => $rows->where('status', AgentRunStatus::Failed)->count(),
                    'cost_minor' => $cost($usage->where('agent_id', $id)),
                ])->values()->all(),
            ],
            'maintenance' => [
                'active_plans' => MaintenancePlan::query()->where('status', MaintenancePlanStatus::Active)->count(),
                'visits_due' => $in(MaintenanceTask::query(), 'scheduled_at')->count(),
                'visits_completed' => $in(MaintenanceTask::query(), 'completed_at')->where('status', MaintenanceTaskStatus::Completed)->count(),
                'visits_failed' => $in(MaintenanceTask::query(), 'completed_at')->where('status', MaintenanceTaskStatus::Failed)->count(),
                'visits_overdue' => MaintenanceTask::query()->overdue()->count(),
                'findings_open' => MaintenanceFinding::query()->whereNotIn('status', [FindingStatus::Resolved, FindingStatus::WontFix, FindingStatus::Rejected])->count(),
                'findings_by_severity' => $in(MaintenanceFinding::query())->get(['severity'])->countBy(fn ($f) => $f->severity->value)->all(),
                'renewals_due' => MaintenancePlan::query()->where('status', MaintenancePlanStatus::Active)->whereDate('term_ends_at', '<=', today()->addDays((int) config('company_os.maintenance.renewal_reminder_days')))->count(),
                // What active plans are priced at; not money received.
                'contracted_value_minor' => MaintenancePlan::query()->where('status', MaintenancePlanStatus::Active)->get(['amount_minor', 'currency', 'billing_frequency'])
                    ->groupBy(fn ($p) => "{$p->currency} {$p->billing_frequency->value}")->map(fn ($rows) => (int) $rows->sum('amount_minor'))->all(),
            ],
            'costs' => [
                'currency' => config('company_os.ai.currency'),
                'ai_cost_minor' => $cost($usage),
                'ai_cost_is_estimated' => $usage->contains(fn ($u) => $u->actual_cost_minor === null),
                'ai_cost_by_model' => $usage->groupBy('model')->map(fn ($rows) => $cost($rows))->all(),
                'ai_cost_per_completed_task_minor' => $completedTasks > 0 ? intdiv($cost($usage), $completedTasks) : null,
                'agent_tasks_completed' => $completedTasks,
            ],
            'errors' => [
                'failed_tool_calls' => $in(ToolExecution::query())->where('status', ToolExecutionStatus::Failed)->count(),
                'failed_approvals' => $in(ApprovalRequest::query(), 'updated_at')->where('status', ApprovalStatus::Failed)->count(),
                'needs_reconciliation' => ApprovalRequest::query()->where('status', ApprovalStatus::NeedsReconciliation)->count(),
                'alerts_raised' => $in(Alert::query())->count(),
                'alerts_open' => Alert::query()->open()->count(),
            ],
            'not_recorded' => ['Paid invoices and verified revenue', 'Expenses other than AI usage', 'Hosting costs', 'Customer support issues', 'Customer satisfaction'],
        ];
        $data['priorities'] = $this->priorities($data);

        return $data;
    }

    /**
     * Store the report for a period that has ended. Generating it again
     * returns the stored one.
     */
    public function store(ReportPeriod $period, Carbon $date): OperatingReport
    {
        [$from, $to] = $period->bounds($date);
        if ($to->gt(now())) {
            throw new \LogicException('Only finished periods are stored.');
        }

        if ($existing = OperatingReport::query()->where('period', $period)->whereDate('period_start', $from)->first()) {
            return $existing;
        }

        try {
            return OperatingReport::query()->create([
                'period' => $period,
                'period_start' => $from->toDateString(),
                'period_end' => $to->copy()->subDay()->toDateString(),
                'data' => $this->build($period, $date),
                'generated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return OperatingReport::query()->where('period', $period)->whereDate('period_start', $from)->sole();
        }
    }

    /**
     * Store the reports for the periods that ended most recently: always
     * yesterday, last week on Mondays, last month on the 1st.
     *
     * @return list<OperatingReport>
     */
    public function storeDue(): array
    {
        $now = now();
        $due = [ReportPeriod::Daily];
        if ($now->isMonday()) {
            $due[] = ReportPeriod::Weekly;
        }
        if ($now->day === 1) {
            $due[] = ReportPeriod::Monthly;
        }

        return array_map(fn (ReportPeriod $p) => $this->store($p, $p->previous($now)), $due);
    }

    /**
     * Fixed rules over the report's numbers. Advisory only.
     *
     * @param  array<string, mixed>  $d
     * @return list<string>
     */
    private function priorities(array $d): array
    {
        $rules = [
            [$d['errors']['needs_reconciliation'], fn ($n) => "Reconcile {$n} approval(s) whose outcome is unknown before anything else."],
            [$d['projects']['releases_failed_verification'], fn ($n) => "{$n} release(s) failed their health check: check the sites or roll back."],
            [$d['maintenance']['visits_overdue'], fn ($n) => "{$n} maintenance visit(s) are overdue."],
            [$d['sales']['proposals_awaiting_approval'], fn ($n) => "{$n} proposal(s) are waiting for your approval."],
            [$d['projects']['overdue_milestones'], fn ($n) => "{$n} project milestone(s) are overdue."],
            [$d['tasks']['blocked'], fn ($n) => "{$n} task(s) are blocked."],
            [$d['maintenance']['renewals_due'], fn ($n) => "{$n} maintenance plan(s) are due for renewal soon."],
            [$d['tasks']['awaiting_review'], fn ($n) => "{$n} agent result(s) are waiting for review."],
            [$d['maintenance']['findings_open'], fn ($n) => "{$n} maintenance finding(s) are still open."],
            [$d['errors']['alerts_open'], fn ($n) => "{$n} alert(s) are not acknowledged."],
        ];

        return array_values(array_map(fn ($r) => $r[1]($r[0]), array_filter($rules, fn ($r) => $r[0] > 0)));
    }
}
