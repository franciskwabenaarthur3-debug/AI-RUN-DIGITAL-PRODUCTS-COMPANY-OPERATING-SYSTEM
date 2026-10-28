<?php

namespace Tests\Feature\Projects;

use App\Enums\CheckStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectRelease;
use App\Models\Proposal;
use App\Releases\HealthChecker;
use App\Services\ApprovalService;
use App\Services\ProjectService;
use App\Services\ReleaseService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Proposals\InteractsWithProposals;

trait InteractsWithProjects
{
    use InteractsWithProposals;

    protected function setUpProjects(): void
    {
        $this->setUpProposals();
        // Health checks resolve every host to a public documentation address.
        $this->app->instance(HealthChecker::class, new HealthChecker(fn (string $host) => ['93.184.216.34']));
    }

    protected function projects(): ProjectService
    {
        return app(ProjectService::class);
    }

    protected function releases(): ReleaseService
    {
        return app(ReleaseService::class);
    }

    protected function acceptedProposal(array $data = []): Proposal
    {
        return $this->proposals()->recordResponse($this->sentProposal($data), 'accepted', 'Client replied yes by email', today(), $this->operator)->fresh();
    }

    protected function project(): Project
    {
        $project = Project::query()->where('proposal_id', $this->acceptedProposal()->id)->sole();
        $project->update(['staging_url' => 'https://staging.acme-bakery.example', 'production_url' => 'https://acme-bakery.example']);

        return $project->fresh();
    }

    protected function moveTo(Project $project, ProjectStatus ...$steps): Project
    {
        foreach ($steps as $step) {
            $project = $this->projects()->changeStatus($project, $step, $this->operator, 'Step note');
        }

        return $project->fresh();
    }

    protected function release(Project $project, string $environment = 'staging', string $version = '1.0.0'): ProjectRelease
    {
        return $this->releases()->create($project, [
            'environment' => $environment,
            'version' => $version,
            'commit_reference' => 'a1b2c3d',
            'release_notes' => 'First full site.',
            'rollback_plan' => 'Restore last night\'s backup from the host panel.',
        ], $this->operator);
    }

    protected function passChecks(ProjectRelease $release, bool $optionalToo = false): ProjectRelease
    {
        foreach ($release->checks()->get() as $check) {
            if ($check->mandatory || $optionalToo) {
                $this->releases()->recordCheck($check, CheckStatus::Passed, "CI run #42 for {$check->check_key}", $this->operator);
            }
        }

        return $release->fresh();
    }

    protected function verifiedStaging(Project $project, string $version = '1.0.0'): ProjectRelease
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $release = $this->release($project, 'staging', $version);
        $this->releases()->markStagingDeployed($release, 'Pushed to staging with the host panel', $this->operator);

        return $this->releases()->runHealthCheck($release, $this->operator);
    }

    /** A project the client approved for release, with version 1.0.0 verified on staging. */
    protected function readyProject(): Project
    {
        $project = $this->project();
        $project = $this->moveTo($project, ProjectStatus::Planning, ProjectStatus::InProgress, ProjectStatus::InternalReview);
        $this->verifiedStaging($project);

        return $this->moveTo($project, ProjectStatus::ClientReview, ProjectStatus::ApprovedForRelease);
    }

    protected function approvedProduction(Project $project): ProjectRelease
    {
        $release = $this->passChecks($this->release($project, 'production'));
        $release = $this->releases()->submit($release, $this->operator);
        app(ApprovalService::class)->decide($release->approvalRequest, $this->founder, true);

        return $release->fresh();
    }
}
