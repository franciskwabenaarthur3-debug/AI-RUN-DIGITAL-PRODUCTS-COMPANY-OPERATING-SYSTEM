<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\ReleaseStatus;
use App\Models\ProjectRelease;
use App\Releases\HealthChecker;
use App\Releases\ReleaseRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReleaseVerificationTest extends TestCase
{
    use InteractsWithProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjects();
    }

    private function deployedStaging(): ProjectRelease
    {
        $release = $this->release($this->project());

        return $this->releases()->markStagingDeployed($release, 'Pushed with the host panel', $this->operator);
    }

    public function test_a_healthy_production_release_marks_the_project_delivered(): void
    {
        $project = $this->readyProject();
        $release = $this->releases()->recordDeployment($this->approvedProduction($project), 'Deployed tag 1.0.0', $this->founder);

        Http::fake(['https://acme-bakery.example' => Http::response('<h1>Acme</h1>', 200)]);
        $release = $this->releases()->runHealthCheck($release, $this->operator);

        $this->assertSame(ReleaseStatus::Verified, $release->status);
        $this->assertSame(200, $release->health_detail['http_status']);
        $this->assertSame(ProjectStatus::Delivered, $project->fresh()->status);
        $this->assertNotNull($project->fresh()->delivered_at);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://acme-bakery.example' && $r->method() === 'GET');
    }

    public function test_an_unhealthy_release_fails_verification_and_cannot_be_verified_by_hand(): void
    {
        $release = $this->deployedStaging();
        Http::fakeSequence()->push('Server error', 500)->push('ok', 200);

        $release = $this->releases()->runHealthCheck($release, $this->operator);

        $this->assertSame(ReleaseStatus::FailedVerification, $release->status);
        $this->assertSame('Answered with HTTP 500.', $release->health_detail['error']);
        $this->assertThrows(fn () => $this->releases()->recordManualVerification($release, 'Looks fine to me', $this->operator), ReleaseRefused::class);

        // A later check can still pass.
        $this->assertSame(ReleaseStatus::Verified, $this->releases()->runHealthCheck($release, $this->operator)->status);
    }

    public function test_redirects_are_reported_not_followed(): void
    {
        $release = $this->deployedStaging();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest'])]);

        $release = $this->releases()->runHealthCheck($release, $this->operator);

        $this->assertSame(ReleaseStatus::FailedVerification, $release->status);
        $this->assertStringContainsString('Redirected to http://169.254.169.254/latest', $release->health_detail['error']);
        Http::assertSentCount(1);
    }

    public function test_private_and_reserved_addresses_are_never_contacted(): void
    {
        Http::fake();
        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '::1'] as $ip) {
            $result = (new HealthChecker(fn () => [$ip]))->check('https://internal.example');
            $this->assertFalse($result['healthy']);
            $this->assertStringContainsString('private or reserved', $result['error']);
        }
        $this->assertStringContainsString('private or reserved', (new HealthChecker)->check('http://127.0.0.1:8080/')['error']);
        $this->assertStringContainsString('without credentials', (new HealthChecker)->check('https://user:pw@example.com')['error']);
        $this->assertStringContainsString('http(s)', (new HealthChecker)->check('file:///etc/passwd')['error']);
        $this->assertStringContainsString('did not resolve', (new HealthChecker(fn () => []))->check('https://nowhere.example')['error']);
        // One private address among public ones is enough to refuse.
        $this->assertFalse((new HealthChecker(fn () => ['93.184.216.34', '192.168.1.1']))->check('https://mixed.example')['healthy']);
        Http::assertNothingSent();
    }

    public function test_private_hosts_can_be_allowed_for_local_testing(): void
    {
        config(['company_os.projects.health_check_allow_private_hosts' => true]);
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->assertTrue((new HealthChecker(fn () => ['127.0.0.1']))->check('http://localhost:8000')['healthy']);
    }

    public function test_connection_errors_are_recorded_as_unhealthy(): void
    {
        $release = $this->deployedStaging();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $release = $this->releases()->runHealthCheck($release, $this->operator);

        $this->assertSame(ReleaseStatus::FailedVerification, $release->status);
        $this->assertStringContainsString('Connection timed out', $release->health_detail['error']);
    }

    public function test_manual_verification_and_rollback_are_recorded(): void
    {
        $release = $this->releases()->recordManualVerification($this->deployedStaging(), 'Checked behind the staging login', $this->operator);
        $this->assertSame(ReleaseStatus::Verified, $release->status);
        $this->assertSame('manual', $release->health_status);

        $release = $this->releases()->recordRollback($release, 'Checkout broke; restored backup', $this->operator);
        $this->assertSame(ReleaseStatus::RolledBack, $release->status);
        $this->assertNotNull($release->rolled_back_at);
        $this->assertThrows(fn () => $this->releases()->recordRollback($release, 'Again', $this->operator), ReleaseRefused::class);
        $this->assertSame(['release_created', 'release_deployed', 'release_verified', 'release_rolled_back'], $release->project->events()->where('event_type', 'like', 'release_%')->pluck('event_type')->all());
    }

    public function test_a_draft_or_production_release_cannot_be_marked_deployed_without_its_flow(): void
    {
        $project = $this->project();
        $this->assertThrows(fn () => $this->releases()->runHealthCheck($this->release($project), $this->operator), ReleaseRefused::class);
        $this->assertThrows(fn () => $this->releases()->markStagingDeployed($this->release($project, 'production'), 'x', $this->operator), ReleaseRefused::class);
    }
}
