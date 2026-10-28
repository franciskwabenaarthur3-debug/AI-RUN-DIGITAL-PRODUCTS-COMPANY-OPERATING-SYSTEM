<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\ApprovalStatus;
use App\Enums\CustomerStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\MaintenanceFrequency;
use App\Enums\MaintenancePlanStatus;
use App\Enums\MaintenanceTaskStatus;
use App\Enums\RiskLevel;
use App\Events\ApprovalDecided;
use App\Exceptions\InvalidTransition;
use App\Governance\ApprovalRefused;
use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\MaintenanceFinding;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use App\Models\Project;
use App\Models\User;
use App\Releases\HealthChecker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maintenance plans, the visits they schedule, findings, approval of risky
 * changes, completion evidence and renewals.
 *
 * Visits only run non-destructive checks. A change to a live site needs
 * the founder's approval of the exact change and rollback plan, and a
 * person makes the change; Company OS never changes a site itself.
 */
class MaintenanceService
{
    public const CHANGE_ACTION = 'maintenance.risky_change';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovalService $approvals,
        private readonly AlertService $alerts,
        private readonly HealthChecker $health,
    ) {}

    /**
     * @param  array{customer_id: int, project_id?: int|null, plan_name: string, billing_frequency: string, service_interval: string, amount_minor: int, currency: string, checklist?: list<string>|null, next_service_at?: string|null, term_ends_at?: string|null, notes?: string|null}  $data
     */
    public function createPlan(array $data, User $actor): MaintenancePlan
    {
        $this->founderOnly($actor);
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $project = filled($data['project_id'] ?? null) ? Project::query()->findOrFail($data['project_id']) : null;

        if ($customer->status !== CustomerStatus::Active) {
            throw new InvalidTransition('Maintenance plans are for active customers.');
        }
        if ($project && $project->customer_id !== $customer->id) {
            throw new InvalidTransition('That project belongs to another customer.');
        }
        if ($data['amount_minor'] <= 0) {
            throw new InvalidTransition('Enter the agreed price.');
        }

        return DB::transaction(function () use ($data, $customer, $project, $actor) {
            $plan = MaintenancePlan::query()->create([
                'customer_id' => $customer->id,
                'project_id' => $project?->id,
                'plan_name' => $data['plan_name'],
                'billing_frequency' => MaintenanceFrequency::from($data['billing_frequency']),
                'service_interval' => MaintenanceFrequency::from($data['service_interval']),
                'amount_minor' => $data['amount_minor'],
                'currency' => $data['currency'],
                'checklist' => $data['checklist'] ?: config('company_os.maintenance.default_checklist'),
                'next_service_at' => $data['next_service_at'] ?? today()->toDateString(),
                'term_ends_at' => $data['term_ends_at'] ?? today()->addYear()->toDateString(),
                'status' => MaintenancePlanStatus::Active,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);
            $this->audit->record('maintenance.plan_created', $plan, ['customer_id' => $customer->id, 'amount_minor' => $plan->amount_minor, 'currency' => $plan->currency], $actor);

            return $plan;
        });
    }

    /**
     * The price, schedule and checklist are the founder's to change.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePlan(MaintenancePlan $plan, array $data, User $actor): MaintenancePlan
    {
        $this->founderOnly($actor);

        return DB::transaction(function () use ($plan, $data, $actor) {
            $plan = MaintenancePlan::query()->lockForUpdate()->findOrFail($plan->id);
            if (filled($data['project_id'] ?? null) && Project::query()->whereKey($data['project_id'])->value('customer_id') !== $plan->customer_id) {
                throw new InvalidTransition('That project belongs to another customer.');
            }

            $plan->fill(array_intersect_key($data, array_flip(['project_id', 'plan_name', 'billing_frequency', 'service_interval', 'amount_minor', 'currency', 'checklist', 'next_service_at', 'notes'])));
            $changed = array_keys($plan->getDirty());

            if ($changed !== []) {
                $old = array_intersect_key($plan->getOriginal(), array_flip(['amount_minor', 'currency']));
                $plan->save();
                $this->audit->record('maintenance.plan_updated', $plan, ['fields' => $changed] + (array_intersect($changed, ['amount_minor', 'currency']) ? ['old' => $old, 'new' => ['amount_minor' => $plan->amount_minor, 'currency' => $plan->currency]] : []), $actor);
            }

            return $plan;
        });
    }

    public function setPlanStatus(MaintenancePlan $plan, MaintenancePlanStatus $status, string $reason, User $actor): MaintenancePlan
    {
        $this->founderOnly($actor);

        return DB::transaction(function () use ($plan, $status, $reason, $actor) {
            $plan = MaintenancePlan::query()->lockForUpdate()->findOrFail($plan->id);
            $allowed = match ($plan->status) {
                MaintenancePlanStatus::Active => [MaintenancePlanStatus::Paused, MaintenancePlanStatus::Cancelled, MaintenancePlanStatus::Ended],
                MaintenancePlanStatus::Paused => [MaintenancePlanStatus::Active, MaintenancePlanStatus::Cancelled],
                default => [],
            };
            if (! in_array($status, $allowed, true)) {
                throw new InvalidTransition("A {$plan->status->label()} plan cannot become {$status->label()}.");
            }

            $from = $plan->status;
            $plan->status = $status;
            $plan->save();
            $this->audit->record('maintenance.plan_status_changed', $plan, ['from' => $from->value, 'to' => $status->value, 'reason' => $reason], $actor);

            return $plan;
        });
    }

    /**
     * Record a renewal the client agreed to: the term moves to a new end
     * date. Reminders for the old end date stop counting.
     */
    public function renew(MaintenancePlan $plan, Carbon $newEnd, string $evidence, User $actor): MaintenancePlan
    {
        $this->founderOnly($actor);

        return DB::transaction(function () use ($plan, $newEnd, $evidence, $actor) {
            $plan = MaintenancePlan::query()->lockForUpdate()->findOrFail($plan->id);

            if (! in_array($plan->status, [MaintenancePlanStatus::Active, MaintenancePlanStatus::Ended], true)) {
                throw new InvalidTransition("A {$plan->status->label()} plan cannot be renewed.");
            }
            if ($newEnd->lte($plan->term_ends_at)) {
                throw new InvalidTransition('The new end date must be after the current one ('.$plan->term_ends_at->format('M j, Y').').');
            }

            $old = $plan->term_ends_at->toDateString();
            $plan->term_ends_at = $newEnd;
            $plan->status = MaintenancePlanStatus::Active;
            $plan->next_service_at ??= today();
            $plan->save();
            $this->audit->record('maintenance.plan_renewed', $plan, ['from' => $old, 'to' => $newEnd->toDateString(), 'evidence' => $evidence], $actor);

            return $plan;
        });
    }

    /**
     * Create visits that are due within the scheduling window, one per
     * plan per run, and move each plan's next service date on. Running it
     * again creates nothing new. Plans whose term ended are marked ended.
     */
    public function scheduleDue(): int
    {
        $horizon = today()->addDays((int) config('company_os.maintenance.schedule_ahead_days'));
        $created = 0;
        $ids = MaintenancePlan::query()->where('status', MaintenancePlanStatus::Active)->whereDate('next_service_at', '<=', $horizon)->pluck('id');

        foreach ($ids as $id) {
            $created += DB::transaction(function () use ($id, $horizon) {
                $plan = MaintenancePlan::query()->lockForUpdate()->find($id);

                if (! $plan || $plan->status !== MaintenancePlanStatus::Active || ! $plan->next_service_at || $plan->next_service_at->gt($horizon)) {
                    return 0;
                }
                if ($plan->next_service_at->gt($plan->term_ends_at)) {
                    $plan->status = MaintenancePlanStatus::Ended;
                    $plan->save();
                    $this->audit->record('maintenance.plan_ended', $plan, ['term_ends_at' => $plan->term_ends_at->toDateString()], actorType: 'system');

                    return 0;
                }

                $date = $plan->next_service_at->copy();
                $made = $this->createTask($plan, $date, 'scheduler', null, null) ? 1 : 0;
                $plan->next_service_at = $plan->service_interval->after($date);
                $plan->save();

                return $made;
            });
        }

        return $created;
    }

    /** A person or agent schedules an extra visit. One visit per plan per day. */
    public function scheduleTask(MaintenancePlan $plan, Carbon $date, User|Agent $actor, ?string $title = null): MaintenanceTask
    {
        if ($plan->status !== MaintenancePlanStatus::Active) {
            throw new InvalidTransition("The plan is {$plan->status->label()}; it takes no new visits.");
        }
        if ($date->lt(today())) {
            throw new InvalidTransition('Choose today or a later date.');
        }

        return DB::transaction(function () use ($plan, $date, $actor, $title) {
            $task = $this->createTask($plan, $date, $actor instanceof Agent ? 'agent' : 'user', $actor->id, $title);
            if (! $task) {
                throw new InvalidTransition('A visit is already scheduled for that day.');
            }
            $this->audit->record('maintenance.task_scheduled', $task, ['plan_id' => $plan->id, 'scheduled_at' => $date->toDateString()], $actor);

            return $task;
        });
    }

    public function start(MaintenanceTask $task, User $actor): MaintenanceTask
    {
        return $this->locked($task, function (MaintenanceTask $task) use ($actor) {
            if ($task->status !== MaintenanceTaskStatus::Scheduled) {
                throw new InvalidTransition("This visit is {$task->status->label()}.");
            }
            $task->status = MaintenanceTaskStatus::InProgress;
            $task->started_at = now();
            $task->save();
            $this->audit->record('maintenance.task_started', $task, [], $actor);

            return $task;
        });
    }

    /**
     * @param  'ok'|'issue'|'not_applicable'|'pending'  $result
     */
    public function recordChecklistItem(MaintenanceTask $task, int $index, string $result, ?string $note, User $actor): MaintenanceTask
    {
        return $this->locked($task, function (MaintenanceTask $task) use ($index, $result, $note, $actor) {
            $this->mustBeInProgress($task);
            $items = $task->checklist;
            if (! isset($items[$index])) {
                throw new InvalidTransition('Unknown checklist item.');
            }
            if (! in_array($result, ['ok', 'issue', 'not_applicable', 'pending'], true)) {
                throw new InvalidTransition('Unknown result.');
            }
            if ($result === 'issue' && blank($note)) {
                throw new InvalidTransition('Say what the issue is, and record it as a finding if it needs work.');
            }

            $items[$index] = ['item' => $items[$index]['item'], 'result' => $result, 'note' => $note, 'by' => $actor->name, 'at' => now()->toIso8601String()];
            $task->checklist = $items;
            $task->save();

            return $task;
        });
    }

    /**
     * One GET of the plan's production URL, recorded on the visit. The
     * request runs outside any transaction. A failure is recorded as a
     * high-severity finding and raises an alert.
     */
    public function runHealthCheck(MaintenanceTask $task, User $actor): MaintenanceTask
    {
        $task = $task->fresh(['plan.project']);
        $this->mustBeInProgress($task);
        $url = $task->plan->siteUrl();
        if (! $url) {
            throw new InvalidTransition('The plan has no project with a production URL to check.');
        }

        $result = $this->health->check($url) + ['checked_at' => now()->toIso8601String()];

        return $this->locked($task, function (MaintenanceTask $task) use ($result, $actor) {
            $this->mustBeInProgress($task);
            $task->health_detail = $result;
            $task->save();
            $this->audit->record('maintenance.health_checked', $task, ['healthy' => $result['healthy'], 'http_status' => $result['http_status']], $actor);

            if (! $result['healthy']) {
                $this->addFinding($task, [
                    'severity' => FindingSeverity::High->value,
                    'description' => "Health check of {$result['url']} failed: {$result['error']}",
                    'recommendation' => 'Check the site and its hosting now.',
                    'risky' => false,
                ], $actor);
            }

            return $task;
        });
    }

    /**
     * @param  array{severity: string, description: string, recommendation?: string|null, risky: bool}  $data
     */
    public function addFinding(MaintenanceTask $task, array $data, User $actor): MaintenanceFinding
    {
        return $this->locked($task, function (MaintenanceTask $task) use ($data, $actor) {
            $this->mustBeInProgress($task);
            $finding = $task->findings()->create([
                'severity' => FindingSeverity::from($data['severity']),
                'description' => $data['description'],
                'recommendation' => $data['recommendation'] ?? null,
                'risky' => (bool) $data['risky'],
                'status' => FindingStatus::Open,
                'recorded_by_user_id' => $actor->id,
            ]);
            $this->audit->record('maintenance.finding_recorded', $task, ['finding_id' => $finding->id, 'severity' => $finding->severity->value, 'risky' => $finding->risky], $actor);

            if ($finding->severity->raisesAlert()) {
                $this->alerts->raise('maintenance_finding', $finding->severity === FindingSeverity::Critical ? AlertSeverity::Critical : AlertSeverity::Warning,
                    "{$finding->severity->label()} finding on {$task->plan->customer->company_name}'s site",
                    Str::limit($finding->description, 300), "maintenance_finding:{$finding->id}", 'maintenance_task', $task->id);
            }

            return $finding;
        });
    }

    /** Close a finding that needs no live change, or that will not be fixed. */
    public function resolveFinding(MaintenanceFinding $finding, FindingStatus $status, string $note, User $actor): MaintenanceFinding
    {
        return $this->lockedFinding($finding, function (MaintenanceFinding $finding) use ($status, $note, $actor) {
            if (! in_array($status, [FindingStatus::Resolved, FindingStatus::WontFix], true)) {
                throw new InvalidTransition('Choose resolved or won\'t fix.');
            }
            if ($finding->status !== FindingStatus::Open) {
                throw new InvalidTransition("This finding is {$finding->status->label()}.");
            }
            if ($status === FindingStatus::Resolved && $finding->risky) {
                throw new InvalidTransition('Fixing this changes the live site: request the founder\'s approval first.');
            }

            return $this->closeFinding($finding, $status, $note, $actor);
        });
    }

    /** Ask the founder to approve a change to the live site, with how to undo it. */
    public function requestChangeApproval(MaintenanceFinding $finding, string $changePlan, string $rollbackPlan, User $actor): MaintenanceFinding
    {
        return $this->lockedFinding($finding, function (MaintenanceFinding $finding) use ($changePlan, $rollbackPlan, $actor) {
            $current = $finding->approvalRequest;
            // Asking again is allowed once the earlier approval can no longer be used.
            $usable = $current && in_array($current->status, [ApprovalStatus::Pending, ApprovalStatus::Approved, ApprovalStatus::Executing, ApprovalStatus::Executed], true) && ! $current->isExpired();
            $again = in_array($finding->status, [FindingStatus::ApprovalRequested, FindingStatus::Approved], true) && ! $usable;
            if ($finding->status !== FindingStatus::Open && ! $again) {
                throw new InvalidTransition("This finding is {$finding->status->label()}.");
            }

            $finding->change_plan = $changePlan;
            $finding->rollback_plan = $rollbackPlan;
            $finding->risky = true;
            $finding->save();

            $task = $finding->task()->with('plan.customer', 'plan.project')->first();
            $attempt = ApprovalRequest::query()->where('action_type', self::CHANGE_ACTION)->where('resource_type', 'maintenance_finding')->where('resource_id', $finding->id)->count() + 1;
            $approval = $this->approvals->request(
                actionType: self::CHANGE_ACTION,
                payload: $this->changePayload($finding),
                reason: "Change {$task->plan->customer->company_name}'s live site ({$task->plan->siteUrl()}) to fix a {$finding->severity->value} finding: ".Str::limit($finding->description, 200),
                risk: RiskLevel::High,
                rollbackPlan: $rollbackPlan,
                idempotencyKey: "maintenance_finding:{$finding->id}:change:{$attempt}",
                requester: $actor,
                resourceType: 'maintenance_finding',
                resourceId: $finding->id,
                evidence: array_filter([$finding->recommendation ? "Recommendation: {$finding->recommendation}" : null, "Visit #{$task->id} on {$task->scheduled_at->format('M j, Y')}"]),
            );

            $finding->status = FindingStatus::ApprovalRequested;
            $finding->approval_request_id = $approval->id;
            $finding->setRelation('approvalRequest', $approval);
            $finding->save();
            $this->audit->record('maintenance.change_requested', $task, ['finding_id' => $finding->id, 'approval_request_id' => $approval->id], $actor);

            return $finding;
        });
    }

    /** Keeps the finding in step with the founder's decision. */
    public function onApprovalDecided(ApprovalDecided $event): void
    {
        $request = $event->request;

        if ($request->action_type !== self::CHANGE_ACTION || $request->resource_type !== 'maintenance_finding') {
            return;
        }

        $finding = MaintenanceFinding::query()->lockForUpdate()->find($request->resource_id);
        if (! $finding || $finding->approval_request_id !== $request->id || $finding->status !== FindingStatus::ApprovalRequested) {
            return;
        }
        if (! $finding->task->status->isOpen()) {
            return;
        }

        $approved = $request->status === ApprovalStatus::Approved;
        $finding->status = $approved ? FindingStatus::Approved : FindingStatus::Rejected;
        if (! $approved) {
            $finding->resolution_note = 'The founder rejected the change: '.$request->decision()->value('reason');
            $finding->resolved_at = now();
        }
        $finding->save();
    }

    /**
     * Record that a person made the approved change. Runs once per
     * approval; a change plan edited after approval is refused.
     */
    public function recordChange(MaintenanceFinding $finding, string $note, User $actor): MaintenanceFinding
    {
        $finding = $finding->fresh(['task', 'approvalRequest']);
        $this->mustBeInProgress($finding->task);
        if ($finding->status !== FindingStatus::Approved || ! $finding->approvalRequest) {
            throw new InvalidTransition('This change has not been approved.');
        }

        try {
            $this->approvals->execute($finding->approvalRequest, self::CHANGE_ACTION, 'maintenance_finding', $finding->id, $this->changePayload($finding),
                fn () => ['change' => 'recorded_by_person', 'by' => $actor->name, 'note' => Str::limit($note, 500)]);
        } catch (ApprovalRefused $e) {
            throw new InvalidTransition($e->getMessage().' Request approval again.');
        }

        return $this->lockedFinding($finding, fn (MaintenanceFinding $f) => $this->closeFinding($f, FindingStatus::Resolved, $note, $actor));
    }

    /**
     * Close the visit. Every checklist item needs a result, every finding
     * must be closed, and the evidence says what was done.
     */
    public function complete(MaintenanceTask $task, string $evidence, User $actor): MaintenanceTask
    {
        return $this->locked($task, function (MaintenanceTask $task) use ($evidence, $actor) {
            $this->mustBeInProgress($task);
            $pending = collect($task->checklist)->where('result', 'pending')->count();
            $open = $task->findings()->get()->reject(fn ($f) => $f->status->isClosed())->count();

            if ($pending > 0) {
                throw new InvalidTransition("{$pending} checklist item(s) have no result yet.");
            }
            if ($open > 0) {
                throw new InvalidTransition("{$open} finding(s) are still open. Resolve them, mark them won't fix, or finish the approved change.");
            }
            if (blank($evidence)) {
                throw new InvalidTransition('Add the evidence: what was checked and done, and where any reports or backups are.');
            }

            $task->status = MaintenanceTaskStatus::Completed;
            $task->evidence = $evidence;
            $task->completed_at = now();
            $task->completed_by_user_id = $actor->id;
            $task->save();
            $this->audit->record('maintenance.task_completed', $task, ['findings' => $task->findings()->count()], $actor);

            return $task;
        });
    }

    /** The visit could not be done; raises an alert. */
    public function fail(MaintenanceTask $task, string $reason, User $actor): MaintenanceTask
    {
        $task = $this->locked($task, function (MaintenanceTask $task) use ($reason, $actor) {
            $this->mustBeInProgress($task);
            $task->status = MaintenanceTaskStatus::Failed;
            $task->notes = trim(($task->notes ? $task->notes."\n" : '').'Failed: '.$reason);
            $task->completed_at = now();
            $task->completed_by_user_id = $actor->id;
            $task->save();
            $this->audit->record('maintenance.task_failed', $task, ['reason' => $reason], $actor);

            return $task;
        });

        $this->alerts->raise('maintenance_failed', AlertSeverity::Critical, "Maintenance visit failed: {$task->title}", $reason, "maintenance_failed:{$task->id}", 'maintenance_task', $task->id);

        return $task;
    }

    public function cancel(MaintenanceTask $task, string $reason, User $actor): MaintenanceTask
    {
        return $this->locked($task, function (MaintenanceTask $task) use ($reason, $actor) {
            if (! $task->status->isOpen()) {
                throw new InvalidTransition("This visit is {$task->status->label()}.");
            }
            foreach ($task->findings()->whereIn('status', [FindingStatus::ApprovalRequested, FindingStatus::Approved])->get() as $finding) {
                if ($finding->approvalRequest) {
                    $this->approvals->invalidate($finding->approvalRequest, 'maintenance_cancelled', 'The maintenance visit was cancelled.');
                }
            }
            $task->status = MaintenanceTaskStatus::Cancelled;
            $task->notes = trim(($task->notes ? $task->notes."\n" : '').'Cancelled: '.$reason);
            $task->save();
            $this->audit->record('maintenance.task_cancelled', $task, ['reason' => $reason], $actor);

            return $task;
        });
    }

    /**
     * What the founder approves for a live change.
     *
     * @return array<string, mixed>
     */
    public function changePayload(MaintenanceFinding $finding): array
    {
        $task = $finding->task()->with('plan.project')->first();

        return [
            'maintenance_task_id' => $task->id,
            'customer_id' => $task->plan->customer_id,
            'site_url' => $task->plan->siteUrl(),
            'severity' => $finding->severity->value,
            'finding' => $finding->description,
            'change_plan' => $finding->change_plan,
            'rollback_plan' => $finding->rollback_plan,
        ];
    }

    private function createTask(MaintenancePlan $plan, Carbon $date, string $source, ?int $sourceId, ?string $title): ?MaintenanceTask
    {
        if (MaintenanceTask::query()->where('maintenance_plan_id', $plan->id)->whereDate('scheduled_at', $date)->exists()) {
            return null;
        }

        try {
            return DB::transaction(fn () => MaintenanceTask::query()->create([
                'maintenance_plan_id' => $plan->id,
                'project_id' => $plan->project_id,
                'title' => $title ?: "{$plan->plan_name}: {$date->format('M Y')} visit",
                'status' => MaintenanceTaskStatus::Scheduled,
                'scheduled_at' => $date->toDateString(),
                'checklist' => array_map(fn ($item) => ['item' => $item, 'result' => 'pending', 'note' => null], $plan->checklist),
                'source' => $source,
                'source_id' => $sourceId,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function closeFinding(MaintenanceFinding $finding, FindingStatus $status, string $note, User $actor): MaintenanceFinding
    {
        $finding->status = $status;
        $finding->resolution_note = $note;
        $finding->resolved_at = now();
        $finding->save();
        $this->audit->record('maintenance.finding_closed', $finding->task, ['finding_id' => $finding->id, 'status' => $status->value], $actor);

        return $finding;
    }

    private function mustBeInProgress(MaintenanceTask $task): void
    {
        if ($task->status !== MaintenanceTaskStatus::InProgress) {
            throw new InvalidTransition("Start the visit first; it is {$task->status->label()}.");
        }
    }

    private function founderOnly(User $actor): void
    {
        if (! $actor->is_active || ! $actor->isFounder()) {
            throw new InvalidTransition('Only the founder manages maintenance plans and their prices.');
        }
    }

    private function locked(MaintenanceTask $task, \Closure $fn): mixed
    {
        return DB::transaction(fn () => $fn(MaintenanceTask::query()->lockForUpdate()->findOrFail($task->id)));
    }

    private function lockedFinding(MaintenanceFinding $finding, \Closure $fn): mixed
    {
        return DB::transaction(function () use ($finding, $fn) {
            $finding = MaintenanceFinding::query()->lockForUpdate()->findOrFail($finding->id);
            $this->mustBeInProgress(MaintenanceTask::query()->lockForUpdate()->findOrFail($finding->maintenance_task_id));

            return $fn($finding);
        });
    }
}
