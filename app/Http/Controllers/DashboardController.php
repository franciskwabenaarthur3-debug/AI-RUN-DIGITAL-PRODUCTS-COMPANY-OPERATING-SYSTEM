<?php

namespace App\Http\Controllers;

use App\Enums\AgentRunStatus;
use App\Enums\ApprovalStatus;
use App\Enums\CustomerStatus;
use App\Enums\LeadStatus;
use App\Enums\MaintenancePlanStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReleaseStatus;
use App\Enums\ReportPeriod;
use App\Models\AgentRun;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use App\Models\ModelUsage;
use App\Models\OperatingReport;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectRelease;
use App\Models\Proposal;
use App\Models\ServicePackage;
use App\Models\Task;
use App\Services\AiBudgetMonitor;
use App\Services\OperatingReportService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OperatingReportService $reports): View
    {
        $settings = CompanySetting::current();
        $user = $request->user();

        return view('dashboard.index', [
            'settings' => $settings,
            'activePackages' => ServicePackage::query()->where('is_active', true)->count(),
            'leadCount' => Lead::query()->count(),
            'qualifiedCount' => Lead::query()->where('status', LeadStatus::Qualified)->count(),
            'followUpsDue' => Lead::query()->followUpDue()->count(),
            'proposalsAwaitingApproval' => Proposal::query()->where('status', ProposalStatus::PendingApproval)->count(),
            'proposalsSent' => Proposal::query()->where('status', ProposalStatus::Sent)->count(),
            'proposalsAccepted' => Proposal::query()->where('status', ProposalStatus::Accepted)->count(),
            'activeProjects' => Project::query()->whereNotIn('status', [ProjectStatus::Delivered, ProjectStatus::Closed, ProjectStatus::Cancelled])->count(),
            'overdueMilestones' => ProjectMilestone::query()->overdue()->whereHas('project', fn ($q) => $q->whereNotIn('status', [ProjectStatus::Delivered, ProjectStatus::Closed, ProjectStatus::Cancelled]))->count(),
            'releasesAwaitingApproval' => ProjectRelease::query()->where('status', ReleaseStatus::PendingApproval)->count(),
            'activePlans' => MaintenancePlan::query()->where('status', MaintenancePlanStatus::Active)->count(),
            'visitsDue' => MaintenanceTask::query()->open()->whereDate('scheduled_at', '<=', today()->addDays(7))->count(),
            'visitsOverdue' => MaintenanceTask::query()->overdue()->count(),
            'renewalsDue' => MaintenancePlan::query()->where('status', MaintenancePlanStatus::Active)->whereDate('term_ends_at', '<=', today()->addDays((int) config('company_os.maintenance.renewal_reminder_days')))->count(),
            'openAlerts' => Alert::query()->open()->selectRaw('severity, COUNT(*) as total')->groupBy('severity')->pluck('total', 'severity'),
            'priorities' => $user->can('viewAny', OperatingReport::class) ? $reports->build(ReportPeriod::Daily, now())['priorities'] : null,
            'customerCount' => Customer::query()->where('status', CustomerStatus::Active)->count(),
            'tasksByStatus' => Task::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'failedRuns' => AgentRun::query()->where('status', AgentRunStatus::Failed)->where('created_at', '>=', now()->subDays(7))->count(),
            'pendingApprovals' => ApprovalRequest::query()->where('status', ApprovalStatus::Pending)->where('expires_at', '>', now())->count(),
            'reconcileApprovals' => ApprovalRequest::query()->where('status', ApprovalStatus::NeedsReconciliation)->count(),
            'aiSpent' => Money::format(ModelUsage::companySpentThisMonthMinor(), config('company_os.ai.currency')),
            'aiAlert' => app(AiBudgetMonitor::class)->status(),
            'settingsReviewed' => AuditLog::query()->where('action', 'settings.updated')->exists(),
            'aiBudget' => Money::format($settings->monthly_ai_budget_minor, config('company_os.ai.currency')),
            'recentAudit' => $user->can('viewAny', AuditLog::class)
                ? AuditLog::query()->latest('id')->limit(8)->get()
                : null,
        ]);
    }
}
