<?php

namespace Tests\Feature\Maintenance;

use App\Enums\CustomerStatus;
use App\Enums\MaintenancePlanStatus;
use App\Enums\MaintenanceTaskStatus;
use App\Enums\ToolExecutionStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Customer;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\InteractsWithGovernance;
use Tests\TestCase;

class MaintenanceSchedulingTest extends TestCase
{
    use InteractsWithGovernance, InteractsWithMaintenance {
        InteractsWithMaintenance::setUpMaintenance as baseSetUpMaintenance;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGovernance();
        $this->baseSetUpMaintenance();
    }

    public function test_repeated_scheduler_runs_do_not_duplicate_visits(): void
    {
        $plan = $this->plan();

        $this->artisan('company-os:schedule-maintenance')->assertSuccessful();
        $this->artisan('company-os:schedule-maintenance')->assertSuccessful();
        $this->assertSame(0, $this->maintenance()->scheduleDue());

        $task = MaintenanceTask::query()->sole();
        $this->assertSame([today()->addDays(3)->toDateString(), 'scheduler', MaintenanceTaskStatus::Scheduled], [$task->scheduled_at->toDateString(), $task->source, $task->status]);
        $this->assertSame(['Check backups', 'Check uptime'], array_column($task->checklist, 'item'));
        $this->assertSame(today()->addDays(3)->addMonthNoOverflow()->toDateString(), $plan->fresh()->next_service_at->toDateString());

        // A run that stopped before moving the plan on does not create the visit twice.
        MaintenancePlan::query()->whereKey($plan->id)->update(['next_service_at' => today()->addDays(3)->toDateString()]);
        $this->assertSame(0, $this->maintenance()->scheduleDue());
        $this->assertSame(1, MaintenanceTask::query()->count());

        // A month later the next visit is created, once.
        $this->travel(1)->months();
        $this->assertSame(1, $this->maintenance()->scheduleDue());
        $this->assertSame(0, $this->maintenance()->scheduleDue());
        $this->assertSame(2, MaintenanceTask::query()->count());
    }

    public function test_paused_plans_get_no_visits_and_plans_past_their_term_end(): void
    {
        $paused = $this->plan(['plan_name' => 'Paused']);
        $this->maintenance()->setPlanStatus($paused, MaintenancePlanStatus::Paused, 'Client asked', $this->founder);
        $ending = $this->plan(['plan_name' => 'Ending', 'term_ends_at' => today()->addDay()->toDateString()]);

        $this->assertSame(0, $this->maintenance()->scheduleDue());

        $this->assertSame(0, MaintenanceTask::query()->count());
        $this->assertSame(MaintenancePlanStatus::Ended, $ending->fresh()->status);
        $this->assertSame(MaintenancePlanStatus::Paused, $paused->fresh()->status);
    }

    public function test_extra_visits_are_one_per_day_and_not_in_the_past(): void
    {
        $plan = $this->plan();
        $this->maintenance()->scheduleTask($plan, today()->addDays(10), $this->operator);

        foreach ([today()->addDays(10), today()->subDay()] as $date) {
            try {
                $this->maintenance()->scheduleTask($plan, $date, $this->operator);
                $this->fail('Expected a refusal.');
            } catch (InvalidTransition) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, MaintenanceTask::query()->count());
    }

    public function test_only_the_founder_manages_plans_and_prices(): void
    {
        $data = ['customer_id' => $this->site->customer_id, 'plan_name' => 'x', 'billing_frequency' => 'monthly', 'service_interval' => 'monthly', 'amount_minor' => 1, 'currency' => 'GHS'];

        $this->expectException(InvalidTransition::class);
        try {
            $this->maintenance()->createPlan($data, $this->operator);
        } finally {
            $this->actingAs($this->operator)->post('/maintenance/plans', ['amount' => '10'] + $data)->assertForbidden();
            $this->assertSame(0, MaintenancePlan::query()->count());
        }
    }

    public function test_plans_only_cover_the_customers_own_project(): void
    {
        $other = Customer::factory()->create(['status' => CustomerStatus::Active]);

        $this->expectException(InvalidTransition::class);
        $this->plan(['customer_id' => $other->id]);
    }

    public function test_renewal_needs_a_later_end_date_and_reactivates_an_ended_plan(): void
    {
        $plan = $this->plan();
        $this->maintenance()->setPlanStatus($plan, MaintenancePlanStatus::Ended, 'Term over', $this->founder);

        try {
            $this->maintenance()->renew($plan, $plan->term_ends_at->copy()->subDay(), 'Email', $this->founder);
            $this->fail('Expected a refusal.');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $renewed = $this->maintenance()->renew($plan, $plan->term_ends_at->copy()->addYear(), 'Client email of 2 Nov', $this->founder);
        $this->assertSame(MaintenancePlanStatus::Active, $renewed->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.plan_renewed']);
    }

    public function test_agents_can_schedule_visits_but_not_twice_for_a_day(): void
    {
        $plan = $this->plan();
        $this->grant('schedule_maintenance_task');
        $input = ['maintenance_plan_id' => $plan->id, 'date' => today()->addDays(5)->toDateString()];

        $first = $this->useTool('schedule_maintenance_task', $input);
        $this->assertSame(ToolExecutionStatus::Succeeded, $first->status, (string) $first->error_message);
        $task = MaintenanceTask::query()->sole();
        $this->assertSame(['agent', $this->agent->id], [$task->source, $task->source_id]);

        $this->assertSame(ToolExecutionStatus::Failed, $this->useTool('schedule_maintenance_task', $input)->status);
        $this->assertSame('invalid_input', $this->useTool('schedule_maintenance_task', ['maintenance_plan_id' => $plan->id, 'date' => today()->subDay()->toDateString()])->error_code);
        $this->assertSame('invalid_input', $this->useTool('schedule_maintenance_task', $input + ['status' => 'completed'])->error_code);
        $this->assertSame(1, MaintenanceTask::query()->count());
    }
}
