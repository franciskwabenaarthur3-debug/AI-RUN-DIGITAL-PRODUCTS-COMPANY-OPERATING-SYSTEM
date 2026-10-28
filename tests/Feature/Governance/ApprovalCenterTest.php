<?php

namespace Tests\Feature\Governance;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalCenterTest extends TestCase
{
    use InteractsWithGovernance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGovernance();
        $this->grant('prepare_release');
    }

    private function approval()
    {
        return $this->useTool('prepare_release', ['version' => '3.1.4', 'notes' => 'Bug fixes'])->approvalRequest;
    }

    public function test_founder_sees_the_exact_payload_and_decision_forms(): void
    {
        $approval = $this->approval();

        $this->actingAs($this->founder)->get('/approvals')->assertOk()->assertSee('prepare_release')->assertSee($this->agent->name);
        $this->actingAs($this->founder)->get("/approvals/{$approval->id}")
            ->assertOk()
            ->assertSee('3.1.4')
            ->assertSee($approval->payload_hash)
            ->assertSee('Delete the release draft.')
            ->assertSee('I reviewed the exact payload above and approve it');
    }

    public function test_approval_needs_confirmation_and_runs_the_action(): void
    {
        $approval = $this->approval();

        $this->actingAs($this->founder)->post("/approvals/{$approval->id}/approve")->assertSessionHasErrors('confirm');
        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);

        $this->actingAs($this->founder)->post("/approvals/{$approval->id}/approve", ['confirm' => '1'])->assertRedirect("/approvals/{$approval->id}");
        $this->assertSame(ApprovalStatus::Executed, $approval->fresh()->status);
        $this->assertSame(1, $this->release->runs);

        $this->actingAs($this->founder)->post("/approvals/{$approval->id}/approve", ['confirm' => '1'])->assertSessionHasErrors('approval');
        $this->assertSame(1, $this->release->runs);
    }

    public function test_rejection_needs_a_reason(): void
    {
        $approval = $this->approval();

        $this->actingAs($this->founder)->post("/approvals/{$approval->id}/reject")->assertSessionHasErrors('reason');
        $this->actingAs($this->founder)->post("/approvals/{$approval->id}/reject", ['reason' => 'Wrong version'])->assertRedirect();

        $this->assertSame(ApprovalStatus::Rejected, $approval->fresh()->status);
        $this->assertSame('Wrong version', ApprovalDecision::query()->sole()->reason);
    }

    public function test_operators_and_viewers_can_look_but_not_decide(): void
    {
        $approval = $this->approval();

        foreach ([User::factory()->operator()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get("/approvals/{$approval->id}")->assertOk()->assertSee("Waiting for the founder's decision", false)->assertDontSee('I reviewed the exact payload');
            $this->actingAs($user)->post("/approvals/{$approval->id}/approve", ['confirm' => '1'])->assertForbidden();
            $this->actingAs($user)->post("/approvals/{$approval->id}/reject", ['reason' => 'No'])->assertForbidden();
        }

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_dashboard_shows_pending_approvals(): void
    {
        $this->approval();

        $this->actingAs($this->founder)->get('/dashboard')->assertOk()->assertSee('1 approval request waiting for a decision.');
    }

    public function test_status_filter(): void
    {
        $this->approval();

        $this->actingAs($this->founder)->get('/approvals?status=executed')->assertOk()->assertSee('No approval requests match this filter.');
        $this->actingAs($this->founder)->get('/approvals?status=nonsense')->assertSessionHasErrors('status');
    }
}
