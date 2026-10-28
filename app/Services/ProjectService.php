<?php

namespace App\Services;

use App\Enums\DeliverableStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReleaseEnvironment;
use App\Enums\ReleaseStatus;
use App\Enums\TaskStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Agent;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectEvent;
use App\Models\ProjectMilestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Projects and their milestones, tasks, deliverables and handover. A
 * project comes from an accepted proposal, exactly once.
 */
class ProjectService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TaskService $tasks,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * Create the project for an accepted proposal, or return the one that
     * already exists. Milestones and tasks come from the package's
     * delivery template (or the default template).
     */
    public function createFromProposal(Proposal $proposal, User $actor): Project
    {
        return DB::transaction(function () use ($proposal, $actor) {
            // Serializes concurrent acceptance events for the same proposal.
            $proposal = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($existing = Project::query()->where('proposal_id', $proposal->id)->first()) {
                return $existing;
            }
            if ($proposal->status !== ProposalStatus::Accepted) {
                throw new InvalidTransition("Proposal {$proposal->label()} is {$proposal->status->label()}; only accepted proposals become projects.");
            }
            if ($proposal->isChangeRequest()) {
                throw new InvalidTransition("Proposal {$proposal->label()} is a change request for an existing project.");
            }

            $package = $proposal->servicePackage;
            $template = $package->delivery_template ?: config('company_os.projects.default_template');
            $start = today();
            $days = max(1, (int) ($proposal->scope_snapshot['estimated_days'] ?? $package->estimated_days));

            $project = Project::query()->create([
                'customer_id' => $proposal->customer_id ?? $proposal->lead?->fresh()->customer_id,
                'proposal_id' => $proposal->id,
                'name' => $proposal->title,
                'description' => $proposal->introduction,
                'status' => ProjectStatus::Created,
                'start_date' => $start,
                'due_date' => $start->copy()->addWeekdays($days),
                'budget_minor' => $proposal->amount_minor,
                'currency' => $proposal->currency,
                'handover_checklist' => array_map(fn ($item) => ['item' => $item, 'done' => false, 'by' => null, 'at' => null], config('company_os.projects.handover_checklist')),
                'created_by_user_id' => $actor->id,
            ]);

            $count = count($template);
            foreach (array_values($template) as $i => $step) {
                $milestone = $project->milestones()->create([
                    'position' => $i,
                    'name' => $step['name'],
                    'status' => MilestoneStatus::Pending,
                    // Spread the package's delivery days across the milestones.
                    'due_at' => $start->copy()->addWeekdays((int) ceil($days * ($i + 1) / $count)),
                ]);
                foreach ($step['tasks'] ?? [] as $title) {
                    $this->tasks->create(['title' => $title, 'project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_at' => $milestone->due_at], $actor);
                }
            }

            $this->event($project, 'created', $actor, null, ['proposal_id' => $proposal->id, 'milestones' => $count]);
            $this->audit->record('project.created', $project, ['proposal_id' => $proposal->id, 'budget_minor' => $project->budget_minor], $actor);

            return $project;
        });
    }

    /**
     * Add an accepted change request to its project: a milestone for the
     * new work and the accepted price on top of the budget. Called once,
     * by ProposalService, when the change request is accepted.
     */
    public function applyChangeRequest(Proposal $proposal, User $actor): Project
    {
        return DB::transaction(function () use ($proposal, $actor) {
            $project = Project::query()->lockForUpdate()->findOrFail($proposal->project_id);

            $milestone = $project->milestones()->create([
                'position' => (int) $project->milestones()->max('position') + 1,
                'name' => "Change request {$proposal->label()}",
                'description' => $proposal->title,
                'status' => MilestoneStatus::Pending,
                'due_at' => today()->addWeekdays(max(1, (int) ($proposal->scope_snapshot['estimated_days'] ?? 5))),
            ]);
            $this->tasks->create(['title' => "Deliver change request {$proposal->label()}: {$proposal->title}", 'project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_at' => $milestone->due_at], $actor);

            $sameCurrency = $proposal->currency === $project->currency;
            if ($sameCurrency) {
                $project->budget_minor += $proposal->amount_minor;
                $project->save();
            }

            $this->event($project, 'change_request_accepted', $actor, null, [
                'proposal_id' => $proposal->id, 'amount_minor' => $proposal->amount_minor, 'currency' => $proposal->currency, 'budget_updated' => $sameCurrency,
            ]);
            $this->audit->record('project.change_request_accepted', $project, ['proposal_id' => $proposal->id, 'budget_minor' => $project->budget_minor], $actor);

            return $project;
        });
    }

    public function changeStatus(Project $project, ProjectStatus $target, User $actor, ?string $note = null): Project
    {
        return DB::transaction(function () use ($project, $target, $actor, $note) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            if (! in_array($target, $project->status->manualTransitions(), true)) {
                throw new InvalidTransition("A project cannot move from {$project->status->label()} to {$target->label()}.");
            }

            match ($target) {
                ProjectStatus::ClientReview => $project->releases()->where('environment', ReleaseEnvironment::Staging)->where('status', ReleaseStatus::Verified)->exists()
                    ?: throw new InvalidTransition('Record a verified staging release before asking the client to review.'),
                ProjectStatus::ApprovedForRelease, ProjectStatus::ChangesRequested, ProjectStatus::Cancelled, ProjectStatus::Blocked, ProjectStatus::OnHold => filled($note)
                    ?: throw new InvalidTransition('Add a note: '.match ($target) {
                        ProjectStatus::ApprovedForRelease => "the client's approval and where it is recorded.",
                        ProjectStatus::ChangesRequested => 'what the client asked to change.',
                        default => 'the reason.',
                    }),
                ProjectStatus::Closed => $project->handoverComplete() ?: throw new InvalidTransition('Finish the handover checklist before closing the project.'),
                default => true,
            };

            $from = $project->status;
            if ($from === ProjectStatus::ApprovedForRelease) {
                $this->withdrawProductionReleases($project, $actor, "Project moved to {$target->label()}.");
            }

            $project->status = $target;
            if ($target === ProjectStatus::Closed) {
                $project->closed_at = now();
            }
            $project->save();

            $this->event($project, 'status_changed', $actor, $note, ['from' => $from->value, 'to' => $target->value]);
            $this->audit->record('project.status_changed', $project, ['from' => $from->value, 'to' => $target->value], $actor);

            return $project;
        });
    }

    /**
     * The client's release approval no longer stands, so production
     * releases waiting for (or holding) the founder's approval are cancelled.
     */
    private function withdrawProductionReleases(Project $project, User $actor, string $reason): void
    {
        $open = $project->releases()->where('environment', ReleaseEnvironment::Production)
            ->whereIn('status', [ReleaseStatus::PendingApproval, ReleaseStatus::Approved])->get();

        foreach ($open as $release) {
            if ($release->approvalRequest) {
                $this->approvals->invalidate($release->approvalRequest, 'project_status_changed', $reason);
            }
            $release->status = ReleaseStatus::Cancelled;
            $release->save();
            $this->event($project, 'release_cancelled', $actor, $reason, ['release_id' => $release->id, 'version' => $release->version]);
        }
    }

    /** Called by ReleaseService when a production release is verified. */
    public function markDelivered(Project $project, User $actor, int $releaseId): void
    {
        $project = Project::query()->lockForUpdate()->findOrFail($project->id);

        if ($project->status !== ProjectStatus::ApprovedForRelease) {
            return;
        }

        $project->status = ProjectStatus::Delivered;
        $project->delivered_at = now();
        $project->save();

        $this->event($project, 'status_changed', $actor, 'Production release verified.', ['from' => ProjectStatus::ApprovedForRelease->value, 'to' => ProjectStatus::Delivered->value, 'release_id' => $releaseId]);
        $this->audit->record('project.delivered', $project, ['release_id' => $releaseId], $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(Project $project, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $project->fill(array_intersect_key($data, array_flip(['name', 'description', 'start_date', 'due_date', 'repository_url', 'staging_url', 'production_url'])));
            $changed = array_keys($project->getDirty());

            if ($changed !== []) {
                $project->save();
                $this->event($project, 'details_updated', $actor, null, ['fields' => $changed]);
                $this->audit->record('project.updated', $project, ['fields' => $changed], $actor);
            }

            return $project;
        });
    }

    /**
     * @param  'in_scope'|'out_of_scope'  $scope
     */
    public function recordFeedback(Project $project, string $note, string $scope, User $actor): ProjectEvent
    {
        $event = $this->event($project, 'client_feedback', $actor, $note, ['scope' => $scope]);
        $this->audit->record('project.feedback_recorded', $project, ['scope' => $scope], $actor);

        return $event;
    }

    public function addMilestone(Project $project, string $name, ?string $dueAt, User $actor): ProjectMilestone
    {
        return DB::transaction(function () use ($project, $name, $dueAt, $actor) {
            $milestone = $project->milestones()->create([
                'position' => (int) $project->milestones()->max('position') + 1,
                'name' => $name,
                'status' => MilestoneStatus::Pending,
                'due_at' => $dueAt,
            ]);
            $this->event($project, 'milestone_added', $actor, null, ['milestone' => $name]);

            return $milestone;
        });
    }

    public function setMilestoneStatus(ProjectMilestone $milestone, MilestoneStatus $status, User $actor): ProjectMilestone
    {
        return DB::transaction(function () use ($milestone, $status, $actor) {
            $milestone = ProjectMilestone::query()->lockForUpdate()->findOrFail($milestone->id);

            if ($status === MilestoneStatus::Completed) {
                $open = $milestone->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])->count();
                if ($open > 0) {
                    throw new InvalidTransition("{$open} task(s) in \"{$milestone->name}\" are not finished.");
                }
            }

            $milestone->status = $status;
            $milestone->completed_at = $status === MilestoneStatus::Completed ? now() : null;
            $milestone->save();

            $this->event($milestone->project, 'milestone_'.$status->value, $actor, null, ['milestone' => $milestone->name]);

            return $milestone;
        });
    }

    /**
     * @param  array{title: string, description?: string|null, milestone_id?: int|null, agent_id?: int|null, assignee_user_id?: int|null, due_at?: string|null}  $data
     */
    public function addTask(Project $project, array $data, User $actor): Task
    {
        if (filled($data['milestone_id'] ?? null) && ! $project->milestones()->whereKey($data['milestone_id'])->exists()) {
            throw new InvalidTransition('That milestone belongs to another project.');
        }

        return DB::transaction(function () use ($project, $data, $actor) {
            $task = $this->tasks->create($data + ['project_id' => $project->id], $actor);
            $this->event($project, 'task_added', $actor, null, ['task_id' => $task->id, 'title' => $task->title]);

            return $task;
        });
    }

    public function addDeliverable(Project $project, array $data, User $actor): ProjectDeliverable
    {
        if (filled($data['milestone_id'] ?? null) && ! $project->milestones()->whereKey($data['milestone_id'])->exists()) {
            throw new InvalidTransition('That milestone belongs to another project.');
        }

        return DB::transaction(function () use ($project, $data, $actor) {
            $deliverable = $project->deliverables()->create([
                'milestone_id' => $data['milestone_id'] ?? null,
                'name' => $data['name'],
                'type' => $data['type'],
                'url' => $data['url'] ?? null,
                'version' => 1,
                'status' => DeliverableStatus::Draft,
            ]);
            $this->event($project, 'deliverable_added', $actor, null, ['deliverable_id' => $deliverable->id, 'name' => $deliverable->name]);
            $this->audit->record('project.deliverable_added', $project, ['deliverable_id' => $deliverable->id], $actor);

            return $deliverable;
        });
    }

    /**
     * A new link is a new version of the deliverable.
     */
    public function updateDeliverable(ProjectDeliverable $deliverable, DeliverableStatus $status, ?string $url, User $actor): ProjectDeliverable
    {
        return DB::transaction(function () use ($deliverable, $status, $url, $actor) {
            $deliverable = ProjectDeliverable::query()->lockForUpdate()->findOrFail($deliverable->id);
            $from = $deliverable->status;

            if (filled($url) && $url !== $deliverable->url) {
                $deliverable->url = $url;
                $deliverable->version++;
            }
            $deliverable->status = $status;
            $deliverable->save();

            $this->event($deliverable->project, 'deliverable_updated', $actor, null, [
                'deliverable_id' => $deliverable->id, 'from' => $from->value, 'to' => $status->value, 'version' => $deliverable->version,
            ]);

            return $deliverable;
        });
    }

    public function setHandoverItem(Project $project, int $index, bool $done, User $actor): Project
    {
        return DB::transaction(function () use ($project, $index, $done, $actor) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $items = $project->handover_checklist;

            if (! isset($items[$index])) {
                throw new InvalidTransition('Unknown checklist item.');
            }

            $items[$index] = ['item' => $items[$index]['item'], 'done' => $done, 'by' => $done ? $actor->name : null, 'at' => $done ? now()->toIso8601String() : null];
            $project->handover_checklist = $items;
            $project->save();

            $this->event($project, $done ? 'handover_item_done' : 'handover_item_reopened', $actor, null, ['item' => $items[$index]['item']]);

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function event(Project $project, string $type, User|Agent|null $actor, ?string $note = null, ?array $metadata = null): ProjectEvent
    {
        return $project->events()->create([
            'event_type' => $type,
            'actor_type' => match (true) {
                $actor instanceof Agent => 'agent',
                $actor instanceof User => 'user',
                default => 'system',
            },
            'actor_id' => $actor?->id,
            'note' => $note,
            'metadata' => $metadata ? $this->audit->redact($metadata) : null,
        ]);
    }

    /** Days until or since the due date, for display. */
    public static function dueLabel(?Carbon $date): string
    {
        if (! $date) {
            return 'No due date';
        }

        return $date->isPast() && ! $date->isToday() ? 'Overdue since '.$date->format('M j') : 'Due '.$date->format('M j, Y');
    }
}
