<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\CheckStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReleaseEnvironment;
use App\Enums\ReleaseStatus;
use App\Enums\RiskLevel;
use App\Events\ApprovalDecided;
use App\Governance\ApprovalRefused;
use App\Governance\Payload;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\ProjectRelease;
use App\Models\ReleaseCheck;
use App\Models\User;
use App\Releases\HealthChecker;
use App\Releases\ReleaseRefused;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staging and production releases, their quality checks, approval,
 * deployment record, health check and rollback.
 *
 * Company OS does not deploy anything itself. People deploy with their
 * own tools and record it here; a production release can only be
 * recorded after every mandatory check passed, the same version was
 * verified on staging, the client approved the release, and the founder
 * approved this exact release. No approval overrides a failed mandatory
 * check.
 */
class ReleaseService
{
    public const DEPLOY_ACTION = 'release.deploy_production';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovalService $approvals,
        private readonly ProjectService $projects,
        private readonly HealthChecker $health,
    ) {}

    /**
     * @param  array{environment: string, version: string, commit_reference?: string|null, target_url?: string|null, release_notes: string, rollback_plan: string}  $data
     */
    public function create(Project $project, array $data, User $actor): ProjectRelease
    {
        $environment = ReleaseEnvironment::from($data['environment']);
        $project = $project->fresh();

        if (! $project->status->isActive()) {
            throw new ReleaseRefused("Project \"{$project->name}\" is {$project->status->label()}; it takes no new releases.");
        }

        $target = $data['target_url'] ?? null;
        $target = filled($target) ? $target : ($environment === ReleaseEnvironment::Production ? $project->production_url : $project->staging_url);
        if (blank($target)) {
            throw new ReleaseRefused("Add the {$environment->label()} URL to the release or to the project first.");
        }

        try {
            return DB::transaction(function () use ($project, $data, $environment, $target, $actor) {
                $release = $project->releases()->create([
                    'environment' => $environment,
                    'version' => $data['version'],
                    'commit_reference' => $data['commit_reference'] ?? null,
                    'target_url' => $target,
                    'release_notes' => $data['release_notes'],
                    'rollback_plan' => $data['rollback_plan'],
                    'status' => ReleaseStatus::Draft,
                    'created_by_user_id' => $actor->id,
                ]);

                foreach (config('company_os.projects.release_checks') as $key => $check) {
                    $release->checks()->create(['check_key' => $key, 'label' => $check['label'], 'mandatory' => (bool) $check['mandatory'], 'status' => CheckStatus::Pending]);
                }

                $this->projects->event($project, 'release_created', $actor, null, ['release_id' => $release->id, 'environment' => $environment->value, 'version' => $release->version]);
                $this->audit->record('release.created', $release, ['project_id' => $project->id, 'environment' => $environment->value, 'version' => $release->version], $actor);

                return $release;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ReleaseRefused("A {$environment->label()} release {$data['version']} already exists for this project. Use a new version number.");
        }
    }

    /**
     * Record a check result from evidence a person has (a CI run, a scan
     * report). Mandatory checks cannot be skipped.
     */
    public function recordCheck(ReleaseCheck $check, CheckStatus $status, ?string $evidence, User $actor): ReleaseCheck
    {
        return DB::transaction(function () use ($check, $status, $evidence, $actor) {
            $check = ReleaseCheck::query()->lockForUpdate()->findOrFail($check->id);
            $release = ProjectRelease::query()->lockForUpdate()->findOrFail($check->project_release_id);

            $open = $release->status === ReleaseStatus::Draft
                || ($release->environment === ReleaseEnvironment::Staging && ! in_array($release->status, [ReleaseStatus::Cancelled, ReleaseStatus::RolledBack], true));
            if (! $open) {
                throw new ReleaseRefused("Checks of {$release->label()} can no longer change; it is {$release->status->label()}.");
            }
            if ($status === CheckStatus::Skipped && $check->mandatory) {
                throw new ReleaseRefused("\"{$check->label}\" is mandatory and cannot be skipped.");
            }
            if (in_array($status, [CheckStatus::Passed, CheckStatus::Failed, CheckStatus::Skipped], true) && blank($evidence)) {
                throw new ReleaseRefused('Add the evidence: a link to the run or report, or what you checked.');
            }

            $from = $check->status;
            $check->status = $status;
            $check->evidence = $status === CheckStatus::Pending ? null : $evidence;
            $check->recorded_by_user_id = $actor->id;
            $check->recorded_at = now();
            $check->save();

            $this->projects->event($release->project, 'check_recorded', $actor, $check->evidence, [
                'release_id' => $release->id, 'check' => $check->check_key, 'from' => $from->value, 'to' => $status->value,
            ]);
            $this->audit->record('release.check_recorded', $release, ['check' => $check->check_key, 'status' => $status->value, 'mandatory' => $check->mandatory], $actor);

            return $check;
        });
    }

    /** Record that a staging release was deployed. Staging needs no approval. */
    public function markStagingDeployed(ProjectRelease $release, string $note, User $actor): ProjectRelease
    {
        return DB::transaction(function () use ($release, $note, $actor) {
            $release = ProjectRelease::query()->lockForUpdate()->findOrFail($release->id);

            if ($release->isProduction()) {
                throw new ReleaseRefused('A production release is recorded only after the founder approves it.');
            }
            if ($release->status !== ReleaseStatus::Draft) {
                throw new ReleaseRefused("{$release->label()} is {$release->status->label()}.");
            }

            $release->status = ReleaseStatus::Deployed;
            $release->deployed_at = now();
            $release->deployment_note = $note;
            $release->save();

            $this->projects->event($release->project, 'release_deployed', $actor, $note, ['release_id' => $release->id, 'environment' => 'staging', 'version' => $release->version]);
            $this->audit->record('release.deployed', $release, ['environment' => 'staging'], $actor);

            return $release;
        });
    }

    /**
     * Why a production release cannot go ahead right now. Empty when it can.
     *
     * @return list<string>
     */
    public function productionProblems(ProjectRelease $release): array
    {
        $release->loadMissing(['checks', 'project']);
        $problems = [];

        foreach ($release->blockingChecks() as $check) {
            $problems[] = "Mandatory check \"{$check->label}\" is {$check->status->label()}.";
        }
        if (! $this->verifiedStaging($release)) {
            $problems[] = "Version {$release->version} has not been verified on staging.";
        }
        if ($release->project->status !== ProjectStatus::ApprovedForRelease) {
            $problems[] = "The project is {$release->project->status->label()}; record the client's approval (Approved for release) first.";
        }

        return $problems;
    }

    /**
     * Ask the founder to approve this exact production release. Also asks
     * again after an approval expired or failed.
     */
    public function submit(ProjectRelease $release, User $actor): ProjectRelease
    {
        // Checked first outside the transaction so the refusal is kept in the history.
        $fresh = $release->fresh();
        if ($fresh->isProduction() && ($problems = $this->productionProblems($fresh))) {
            $this->projects->event($fresh->project, 'release_refused', $actor, null, ['release_id' => $fresh->id, 'problems' => $problems]);

            throw new ReleaseRefused('This release cannot go to production yet:', $problems);
        }

        return DB::transaction(function () use ($release, $actor) {
            $release = ProjectRelease::query()->lockForUpdate()->findOrFail($release->id);
            $current = $release->approvalRequest;

            if (! $release->isProduction()) {
                throw new ReleaseRefused('Staging releases need no approval.');
            }
            $again = in_array($release->status, [ReleaseStatus::PendingApproval, ReleaseStatus::Approved], true);
            if ($again && $current && in_array($current->status, [ApprovalStatus::Pending, ApprovalStatus::Approved, ApprovalStatus::Executing, ApprovalStatus::Executed], true) && ! $current->isExpired()) {
                throw new ReleaseRefused('This release already has an open approval request.');
            }
            if (! $again && $release->status !== ReleaseStatus::Draft) {
                throw new ReleaseRefused("{$release->label()} is {$release->status->label()} and cannot be submitted.");
            }
            if ($problems = $this->productionProblems($release)) {
                throw new ReleaseRefused('This release cannot go to production yet:', $problems);
            }

            $payload = $this->payload($release);
            $attempt = ApprovalRequest::query()->where('action_type', self::DEPLOY_ACTION)->where('resource_type', 'project_release')->where('resource_id', $release->id)->count() + 1;

            $approval = $this->approvals->request(
                actionType: self::DEPLOY_ACTION,
                payload: $payload,
                reason: "Release {$release->version} of \"{$release->project->name}\" to production at {$release->target_url}.",
                risk: RiskLevel::High,
                rollbackPlan: $release->rollback_plan,
                idempotencyKey: "release:{$release->id}:deploy:{$attempt}",
                requester: $actor,
                resourceType: 'project_release',
                resourceId: $release->id,
                evidence: $release->checks->map(fn (ReleaseCheck $c) => "{$c->label}: {$c->status->label()}".($c->evidence ? " ({$c->evidence})" : ''))->all(),
            );

            $release->status = ReleaseStatus::PendingApproval;
            $release->approval_request_id = $approval->id;
            $release->setRelation('approvalRequest', $approval);
            $release->content_hash = hash('sha256', Payload::canonical($payload));
            $release->submitted_at = now();
            $release->save();

            $this->projects->event($release->project, 'release_submitted', $actor, null, ['release_id' => $release->id, 'approval_request_id' => $approval->id]);
            $this->audit->record('release.submitted', $release, ['approval_request_id' => $approval->id, 'content_hash' => $release->content_hash], $actor);

            return $release;
        });
    }

    /** Keeps the release in step with the founder's decision. A rejection returns it to draft. */
    public function onApprovalDecided(ApprovalDecided $event): void
    {
        $request = $event->request;

        if ($request->action_type !== self::DEPLOY_ACTION || $request->resource_type !== 'project_release') {
            return;
        }

        $release = ProjectRelease::query()->lockForUpdate()->find($request->resource_id);

        if (! $release || $release->approval_request_id !== $request->id || $release->status !== ReleaseStatus::PendingApproval) {
            return;
        }

        $approved = $request->status === ApprovalStatus::Approved;
        $decision = $request->decision()->first();
        $approver = User::query()->find($decision?->approver_user_id);

        $release->status = $approved ? ReleaseStatus::Approved : ReleaseStatus::Draft;
        $release->save();

        $this->projects->event($release->project, $approved ? 'release_approved' : 'release_rejected', $approver, $decision?->reason, ['release_id' => $release->id, 'approval_request_id' => $request->id]);
    }

    /**
     * The founder records that they deployed the approved release. Runs
     * once per approval, re-checks every gate, and the approval engine
     * refuses a release that differs from what was approved.
     */
    public function recordDeployment(ProjectRelease $release, string $note, User $actor): ProjectRelease
    {
        if (! $actor->is_active || ! $actor->isFounder()) {
            throw new ReleaseRefused('Only the founder records production deployments.');
        }

        $release = $release->fresh(['checks', 'project', 'approvalRequest']);
        if (! $release->isProduction() || $release->status !== ReleaseStatus::Approved) {
            throw new ReleaseRefused("{$release->label()} is {$release->status->label()}; only an approved production release can be deployed.");
        }
        // Mandatory gates are checked again; no approval overrides them.
        if ($problems = $this->productionProblems($release)) {
            $this->projects->event($release->project, 'release_refused', $actor, null, ['release_id' => $release->id, 'problems' => $problems]);

            throw new ReleaseRefused('This release cannot go to production:', $problems);
        }

        try {
            $result = $this->approvals->execute(
                $release->approvalRequest,
                self::DEPLOY_ACTION,
                'project_release',
                $release->id,
                $this->payload($release),
                fn () => ['deployment' => 'recorded_by_founder', 'note' => Str::limit($note, 500)],
            );
        } catch (ApprovalRefused $e) {
            $this->projects->event($release->project, 'release_refused', $actor, null, ['release_id' => $release->id, 'reason' => $e->reasonCode]);

            throw new ReleaseRefused($e->getMessage().' Request approval again to release this version.');
        }

        return DB::transaction(function () use ($release, $note, $actor, $result) {
            $release = ProjectRelease::query()->lockForUpdate()->findOrFail($release->id);
            $release->status = ReleaseStatus::Deployed;
            $release->deployed_at = now();
            $release->deployment_note = $note;
            $release->save();

            $this->projects->event($release->project, 'release_deployed', $actor, $note, ['release_id' => $release->id, 'environment' => 'production', 'version' => $release->version]);
            $this->audit->record('release.deployed', $release, ['environment' => 'production'] + $result, $actor);

            return $release;
        });
    }

    /**
     * Fetch the release URL once and record what came back. The request
     * runs outside any transaction.
     */
    public function runHealthCheck(ProjectRelease $release, User $actor): ProjectRelease
    {
        if (! in_array($release->fresh()->status, [ReleaseStatus::Deployed, ReleaseStatus::FailedVerification], true)) {
            throw new ReleaseRefused("{$release->label()} is {$release->status->label()}; only a deployed release can be checked.");
        }

        $result = $this->health->check($release->target_url);

        return DB::transaction(function () use ($release, $result, $actor) {
            $release = ProjectRelease::query()->lockForUpdate()->findOrFail($release->id);

            if (! in_array($release->status, [ReleaseStatus::Deployed, ReleaseStatus::FailedVerification], true)) {
                throw new ReleaseRefused("{$release->label()} changed to {$release->status->label()} during the check.");
            }

            $release->status = $result['healthy'] ? ReleaseStatus::Verified : ReleaseStatus::FailedVerification;
            $release->health_status = $result['healthy'] ? 'healthy' : 'unhealthy';
            $release->health_checked_at = now();
            $release->health_detail = $result;
            $release->save();

            $this->projects->event($release->project, $result['healthy'] ? 'release_verified' : 'release_failed_verification', $actor, $result['error'], [
                'release_id' => $release->id, 'http_status' => $result['http_status'], 'duration_ms' => $result['duration_ms'],
            ]);
            $this->audit->record('release.health_checked', $release, ['healthy' => $result['healthy'], 'http_status' => $result['http_status']], $actor);

            if ($result['healthy'] && $release->isProduction()) {
                $this->projects->markDelivered($release->project, $actor, $release->id);
            }

            return $release;
        });
    }

    /**
     * A person confirms the release works when an automatic check cannot
     * reach it (for example a staging site behind a login). Not available
     * after an automatic check failed.
     */
    public function recordManualVerification(ProjectRelease $release, string $note, User $actor): ProjectRelease
    {
        return DB::transaction(function () use ($release, $note, $actor) {
            $release = ProjectRelease::query()->lockForUpdate()->findOrFail($release->id);

            if ($release->status !== ReleaseStatus::Deployed) {
                throw new ReleaseRefused("{$release->label()} is {$release->status->label()}; only a deployed release that has not failed a check can be verified by hand.");
            }

            $release->status = ReleaseStatus::Verified;
            $release->health_status = 'manual';
            $release->health_checked_at = now();
            $release->health_detail = ['verified_by' => $actor->name, 'note' => $note];
            $release->save();

            $this->projects->event($release->project, 'release_verified', $actor, $note, ['release_id' => $release->id, 'method' => 'manual']);
            $this->audit->record('release.verified_manually', $release, [], $actor);

            if ($release->isProduction()) {
                $this->projects->markDelivered($release->project, $actor, $release->id);
            }

            return $release;
        });
    }

    /** Record that a person rolled the release back, following its rollback plan. */
    public function recordRollback(ProjectRelease $release, string $note, User $actor): ProjectRelease
    {
        return DB::transaction(function () use ($release, $note, $actor) {
            $release = ProjectRelease::query()->lockForUpdate()->findOrFail($release->id);

            if (! in_array($release->status, [ReleaseStatus::Deployed, ReleaseStatus::Verified, ReleaseStatus::FailedVerification], true)) {
                throw new ReleaseRefused("{$release->label()} is {$release->status->label()}; only a deployed release can be rolled back.");
            }

            $release->status = ReleaseStatus::RolledBack;
            $release->rolled_back_at = now();
            $release->rollback_note = $note;
            $release->save();

            $this->projects->event($release->project, 'release_rolled_back', $actor, $note, ['release_id' => $release->id, 'version' => $release->version]);
            $this->audit->record('release.rolled_back', $release, ['environment' => $release->environment->value], $actor);

            return $release;
        });
    }

    public function cancel(ProjectRelease $release, string $reason, User $actor): ProjectRelease
    {
        return DB::transaction(function () use ($release, $reason, $actor) {
            $release = ProjectRelease::query()->lockForUpdate()->findOrFail($release->id);

            if (! in_array($release->status, [ReleaseStatus::Draft, ReleaseStatus::PendingApproval, ReleaseStatus::Approved], true)) {
                throw new ReleaseRefused("{$release->label()} is {$release->status->label()} and cannot be cancelled.");
            }
            if ($release->approvalRequest) {
                $this->approvals->invalidate($release->approvalRequest, 'release_cancelled', 'The release was cancelled.');
            }

            $release->status = ReleaseStatus::Cancelled;
            $release->save();

            $this->projects->event($release->project, 'release_cancelled', $actor, $reason, ['release_id' => $release->id, 'version' => $release->version]);
            $this->audit->record('release.cancelled', $release, [], $actor);

            return $release;
        });
    }

    /**
     * What the founder approves: the release, its target, its rollback
     * plan and every check result. Recomputed at deployment; any change
     * means the approval no longer applies.
     *
     * @return array<string, mixed>
     */
    public function payload(ProjectRelease $release): array
    {
        return [
            'project_id' => $release->project_id,
            'environment' => $release->environment->value,
            'version' => $release->version,
            'commit_reference' => $release->commit_reference,
            'target_url' => $release->target_url,
            'release_notes' => $release->release_notes,
            'rollback_plan' => $release->rollback_plan,
            'staging_release_id' => $this->verifiedStaging($release)?->id,
            'checks' => $release->checks()->get()->mapWithKeys(fn (ReleaseCheck $c) => [$c->check_key => [
                'mandatory' => $c->mandatory, 'status' => $c->status->value, 'evidence' => $c->evidence,
            ]])->all(),
        ];
    }

    private function verifiedStaging(ProjectRelease $release): ?ProjectRelease
    {
        return ProjectRelease::query()
            ->where('project_id', $release->project_id)
            ->where('environment', ReleaseEnvironment::Staging)
            ->where('version', $release->version)
            ->where('status', ReleaseStatus::Verified)
            ->first();
    }
}
