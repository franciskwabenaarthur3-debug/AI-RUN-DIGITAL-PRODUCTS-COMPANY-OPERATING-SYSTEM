<?php

namespace Tests\Feature\Projects;

use App\Enums\ApprovalStatus;
use App\Enums\CheckStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReleaseStatus;
use App\Exceptions\InvalidTransition;
use App\Models\ProjectRelease;
use App\Models\ReleaseCheck;
use App\Releases\ReleaseRefused;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ReleaseGatesTest extends TestCase
{
    use InteractsWithProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjects();
    }

    private function refusal(callable $action): ReleaseRefused
    {
        try {
            $action();
        } catch (ReleaseRefused $e) {
            $this->addToAssertionCount(1);

            return $e;
        }
        $this->fail('Expected the action to be refused.');
    }

    public function test_acceptance_case_5_a_failed_mandatory_test_blocks_production_deployment(): void
    {
        $project = $this->readyProject();
        $release = $this->passChecks($this->release($project, 'production'));
        $tests = $release->checks()->where('check_key', 'automated_tests')->sole();
        $this->releases()->recordCheck($tests, CheckStatus::Failed, 'CI run #43: 2 failures in checkout', $this->operator);

        $e = $this->refusal(fn () => $this->releases()->submit($release, $this->operator));
        $this->assertContains('Mandatory check "Automated tests passed" is Failed.', $e->problems);
        $release->refresh();
        $this->assertSame(ReleaseStatus::Draft, $release->status);
        $this->assertNull($release->approval_request_id, 'No approval is even requested.');

        // The founder cannot record a deployment either; there is nothing to approve.
        $this->refusal(fn () => $this->releases()->recordDeployment($release, 'Deployed', $this->founder));
        $this->actingAs($this->founder)->post("/releases/{$release->id}/deploy", ['note' => 'x', 'confirm' => '1'])->assertSessionHasErrors('release');
        $this->assertSame(ReleaseStatus::Draft, $release->fresh()->status);
        $this->assertSame(1, $project->events()->where('event_type', 'release_refused')->count());
    }

    public function test_mandatory_checks_cannot_be_skipped_and_results_need_evidence(): void
    {
        $release = $this->release($this->project());
        $mandatory = $release->checks()->where('mandatory', true)->first();
        $optional = $release->checks()->where('mandatory', false)->first();

        $this->refusal(fn () => $this->releases()->recordCheck($mandatory, CheckStatus::Skipped, 'Not needed', $this->operator));
        $this->refusal(fn () => $this->releases()->recordCheck($mandatory, CheckStatus::Passed, '', $this->operator));
        $this->releases()->recordCheck($optional, CheckStatus::Skipped, 'No old browsers in scope', $this->operator);

        $this->assertSame(CheckStatus::Pending, $mandatory->fresh()->status);
        $this->assertSame(CheckStatus::Skipped, $optional->fresh()->status);
        $this->assertSame(['automated_tests', 'security_scan', 'content_review', 'backup'], $release->checks()->where('mandatory', true)->pluck('check_key')->all());
    }

    public function test_production_needs_a_verified_staging_release_of_the_same_version_and_the_clients_approval(): void
    {
        $project = $this->moveTo($this->project(), ProjectStatus::Planning, ProjectStatus::InProgress);
        $release = $this->passChecks($this->release($project, 'production', '2.0.0'));

        $e = $this->refusal(fn () => $this->releases()->submit($release, $this->operator));
        $this->assertContains('Version 2.0.0 has not been verified on staging.', $e->problems);
        $this->assertContains("The project is In progress; record the client's approval (Approved for release) first.", $e->problems);

        // Verified staging of a different version does not count.
        $this->verifiedStaging($project, '1.9.0');
        $this->assertContains('Version 2.0.0 has not been verified on staging.', $this->releases()->productionProblems($release->fresh()));
    }

    public function test_client_review_needs_a_verified_staging_release(): void
    {
        $project = $this->moveTo($this->project(), ProjectStatus::Planning, ProjectStatus::InProgress, ProjectStatus::InternalReview);

        $this->assertThrows(fn () => $this->projects()->changeStatus($project, ProjectStatus::ClientReview, $this->operator), InvalidTransition::class);
        $this->verifiedStaging($project);
        $this->assertSame(ProjectStatus::ClientReview, $this->projects()->changeStatus($project, ProjectStatus::ClientReview, $this->operator)->status);
    }

    public function test_a_production_release_requires_the_founders_approval(): void
    {
        $project = $this->readyProject();
        $release = $this->releases()->submit($this->passChecks($this->release($project, 'production')), $this->operator);

        $this->assertSame(ReleaseStatus::PendingApproval, $release->status);
        $approval = $release->approvalRequest;
        $this->assertSame('release.deploy_production', $approval->action_type);
        $this->assertSame('high', $approval->risk_level->value);
        $this->assertSame($release->rollback_plan, $approval->rollback_plan);
        $this->assertSame('passed', $approval->payload_snapshot['checks']['automated_tests']['status']);
        $this->assertNotNull($approval->payload_snapshot['staging_release_id']);

        // Not yet approved: the deployment cannot be recorded.
        $this->refusal(fn () => $this->releases()->recordDeployment($release, 'Deployed', $this->founder));
        $this->assertThrows(fn () => app(ApprovalService::class)->decide($approval, $this->operator, true));

        app(ApprovalService::class)->decide($approval, $this->founder, true);
        $this->assertSame(ReleaseStatus::Approved, $release->fresh()->status);

        $this->refusal(fn () => $this->releases()->recordDeployment($release, 'Deployed', $this->operator));
        $deployed = $this->releases()->recordDeployment($release, 'Deployed tag 1.0.0 from the host panel at 14:00', $this->founder);
        $this->assertSame(ReleaseStatus::Deployed, $deployed->status);
        $this->assertSame(ApprovalStatus::Executed, $approval->fresh()->status);

        // Once only.
        $this->refusal(fn () => $this->releases()->recordDeployment($release, 'Again', $this->founder));
    }

    public function test_a_rejection_returns_the_release_to_draft_and_it_can_be_submitted_again(): void
    {
        $release = $this->releases()->submit($this->passChecks($this->release($this->readyProject(), 'production')), $this->operator);

        app(ApprovalService::class)->decide($release->approvalRequest, $this->founder, false, 'Wait for the client to pay the deposit');
        $release->refresh();
        $this->assertSame(ReleaseStatus::Draft, $release->status);

        $again = $this->releases()->submit($release, $this->operator);
        $this->assertNotSame($release->approval_request_id, $again->approval_request_id);
        $this->assertSame(ApprovalStatus::Pending, $again->approvalRequest->status);
    }

    public function test_release_content_and_production_checks_freeze_once_submitted(): void
    {
        $release = $this->releases()->submit($this->passChecks($this->release($this->readyProject(), 'production')), $this->operator);

        $this->assertThrows(fn () => $release->update(['version' => '1.0.1']), LogicException::class);
        $this->assertThrows(fn () => $release->update(['target_url' => 'https://other.example']), LogicException::class);
        $check = $release->checks()->first();
        $this->assertThrows(fn () => $check->update(['status' => CheckStatus::Failed]), LogicException::class);
        $this->refusal(fn () => $this->releases()->recordCheck($check, CheckStatus::Failed, 'x', $this->operator));
    }

    public function test_an_approval_is_invalid_if_the_release_changes_after_it(): void
    {
        $release = $this->approvedProduction($this->readyProject());
        $approval = $release->approvalRequest;

        // Bypass the model guard, as a direct database edit would.
        ProjectRelease::query()->whereKey($release->id)->toBase()->update(['target_url' => 'https://evil.example']);

        $this->refusal(fn () => $this->releases()->recordDeployment($release, 'Deployed', $this->founder));
        $this->assertSame(ApprovalStatus::Invalidated, $approval->fresh()->status);
        $this->assertSame(ReleaseStatus::Approved, $release->fresh()->status);
    }

    public function test_a_changed_check_result_invalidates_the_approval(): void
    {
        $release = $this->approvedProduction($this->readyProject());
        ReleaseCheck::query()->where('project_release_id', $release->id)->where('check_key', 'accessibility')->toBase()->update(['status' => 'failed']);

        $this->refusal(fn () => $this->releases()->recordDeployment($release, 'Deployed', $this->founder));
        $this->assertSame(ApprovalStatus::Invalidated, $release->approvalRequest->fresh()->status);
    }

    public function test_an_approval_cannot_override_a_mandatory_check_that_fails_after_it(): void
    {
        $release = $this->approvedProduction($this->readyProject());
        ReleaseCheck::query()->where('project_release_id', $release->id)->where('check_key', 'security_scan')->toBase()->update(['status' => 'failed']);

        $e = $this->refusal(fn () => $this->releases()->recordDeployment($release, 'Deployed', $this->founder));
        $this->assertContains('Mandatory check "Security scan with no high-severity findings" is Failed.', $e->problems);
        $this->assertSame(ApprovalStatus::Approved, $release->approvalRequest->fresh()->status, 'Refused before the approval was used.');
        $this->assertNull($release->fresh()->deployed_at);
    }

    public function test_moving_the_project_away_from_approved_for_release_cancels_open_production_releases(): void
    {
        $project = $this->readyProject();
        $release = $this->approvedProduction($project);

        $this->projects()->changeStatus($project, ProjectStatus::InProgress, $this->operator, 'Client wants one more change');

        $this->assertSame(ReleaseStatus::Cancelled, $release->fresh()->status);
        $this->assertSame(ApprovalStatus::Invalidated, $release->approvalRequest->fresh()->status);
        $this->refusal(fn () => $this->releases()->recordDeployment($release, 'Deployed', $this->founder));
    }

    public function test_versions_are_unique_per_environment_and_a_url_is_required(): void
    {
        $project = $this->project();
        $this->release($project, 'staging', '1.0.0');

        $this->refusal(fn () => $this->release($project, 'staging', '1.0.0'));
        $this->release($project, 'production', '1.0.0');

        $project->update(['staging_url' => null]);
        $this->refusal(fn () => $this->release($project->fresh(), 'staging', '1.1.0'));
    }

    public function test_cancelling_a_pending_release_invalidates_its_approval(): void
    {
        $release = $this->releases()->submit($this->passChecks($this->release($this->readyProject(), 'production')), $this->operator);

        $this->releases()->cancel($release, 'Wrong build', $this->operator);

        $this->assertSame(ReleaseStatus::Cancelled, $release->fresh()->status);
        $this->assertSame(ApprovalStatus::Invalidated, $release->approvalRequest->fresh()->status);
        $this->assertThrows(fn () => app(ApprovalService::class)->decide($release->approvalRequest->fresh(), $this->founder, true));
    }
}
