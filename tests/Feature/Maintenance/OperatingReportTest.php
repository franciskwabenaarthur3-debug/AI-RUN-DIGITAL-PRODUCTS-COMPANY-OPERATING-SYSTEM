<?php

namespace Tests\Feature\Maintenance;

use App\Enums\ReportPeriod;
use App\Enums\ToolExecutionStatus;
use App\Models\Alert;
use App\Models\Lead;
use App\Models\MaintenanceTask;
use App\Models\OperatingReport;
use App\Services\OperatingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Feature\Governance\InteractsWithGovernance;
use Tests\TestCase;

class OperatingReportTest extends TestCase
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

    private function reports(): OperatingReportService
    {
        return app(OperatingReportService::class);
    }

    public function test_reports_count_recorded_data_and_never_invent_revenue(): void
    {
        $plan = $this->plan();
        $this->maintenance()->scheduleTask($plan, today(), $this->operator);
        $this->travel(2)->days();

        $report = $this->reports()->build(ReportPeriod::Monthly, now());

        // One lead and an accepted proposal from the project set-up.
        $this->assertSame(Lead::query()->count(), $report['sales']['leads_created']);
        $this->assertSame(1, $report['sales']['proposals_accepted']);
        $this->assertSame(['GHS' => $this->site->proposal->amount_minor], $report['sales']['accepted_value_minor']);
        $this->assertSame(100, $report['sales']['proposal_approval_rate_percent']);
        $this->assertSame([1, 1, 1], [$report['maintenance']['active_plans'], $report['maintenance']['visits_due'], $report['maintenance']['visits_overdue']]);
        $this->assertSame(['GHS monthly' => 45_000], $report['maintenance']['contracted_value_minor']);
        $this->assertSame(0, $report['costs']['ai_cost_minor']);
        $this->assertNull($report['costs']['ai_cost_per_completed_task_minor']);
        $this->assertNull($report['projects']['on_time_rate_percent'], 'No deliveries: no rate, not 0% or 100%.');
        $this->assertContains('Paid invoices and verified revenue', $report['not_recorded']);
        $this->assertArrayNotHasKey('revenue', $report['sales']);
        $this->assertContains('1 maintenance visit(s) are overdue.', $report['priorities']);
    }

    public function test_an_empty_company_reports_zeros_and_no_rates(): void
    {
        $report = $this->reports()->build(ReportPeriod::Daily, now()->addYears(2));

        $this->assertSame(0, $report['sales']['leads_created']);
        $this->assertNull($report['sales']['qualified_rate_percent']);
        $this->assertNull($report['tasks']['agent_success_rate_percent']);
    }

    public function test_finished_periods_are_stored_once_and_never_changed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 00:30')); // a Monday

        $this->artisan('company-os:generate-reports')->assertSuccessful();
        $this->artisan('company-os:generate-reports')->assertSuccessful();

        $stored = OperatingReport::query()->orderBy('period')->get();
        $this->assertSame([['daily', '2026-11-01'], ['weekly', '2026-10-26']], $stored->map(fn ($r) => [$r->period->value, $r->period_start->toDateString()])->all());
        $this->assertSame('2026-11-01', $stored->firstWhere('period', ReportPeriod::Weekly)->period_end->toDateString());

        try {
            $this->reports()->store(ReportPeriod::Daily, now());
            $this->fail('The current day is not finished.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $report = $stored->first();
        foreach ([fn () => $report->update(['data' => []]), fn () => $report->delete()] as $change) {
            try {
                $change();
                $this->fail('A stored report changed.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        Carbon::setTestNow(Carbon::parse('2026-12-01 00:30'));
        $this->reports()->storeDue();
        $this->assertTrue(OperatingReport::query()->where('period', ReportPeriod::Monthly)->whereDate('period_start', '2026-11-01')->exists());
        Carbon::setTestNow();
    }

    public function test_the_report_tool_is_read_only(): void
    {
        $this->grant('generate_operating_report');
        $before = [OperatingReport::query()->count(), MaintenanceTask::query()->count(), Alert::query()->count()];

        $execution = $this->useTool('generate_operating_report', ['period' => 'weekly']);

        $this->assertSame(ToolExecutionStatus::Succeeded, $execution->status, (string) $execution->error_message);
        $this->assertSame('weekly', $execution->result_summary['period']);
        $this->assertSame($before, [OperatingReport::query()->count(), MaintenanceTask::query()->count(), Alert::query()->count()]);
        $this->assertSame('invalid_input', $this->useTool('generate_operating_report', ['period' => 'yearly'])->error_code);
    }
}
