<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use App\Models\Project;
use App\Services\MaintenanceService;
use Tests\Feature\Projects\InteractsWithProjects;

trait InteractsWithMaintenance
{
    use InteractsWithProjects;

    protected Project $site;

    protected function setUpMaintenance(): void
    {
        $this->setUpProjects();
        $this->site = $this->project();
    }

    protected function maintenance(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

    protected function plan(array $data = []): MaintenancePlan
    {
        return $this->maintenance()->createPlan($data + [
            'customer_id' => $this->site->customer_id,
            'project_id' => $this->site->id,
            'plan_name' => 'Care plan',
            'billing_frequency' => 'monthly',
            'service_interval' => 'monthly',
            'amount_minor' => 45_000,
            'currency' => 'GHS',
            'checklist' => ['Check backups', 'Check uptime'],
            'next_service_at' => today()->addDays(3)->toDateString(),
        ], $this->founder);
    }

    /** A visit that has been started, with every checklist item marked OK. */
    protected function startedVisit(?MaintenancePlan $plan = null, bool $checked = true): MaintenanceTask
    {
        $plan ??= $this->plan();
        $task = $this->maintenance()->scheduleTask($plan, today(), $this->operator);
        $task = $this->maintenance()->start($task, $this->operator);

        if ($checked) {
            foreach (array_keys($task->checklist) as $i) {
                $task = $this->maintenance()->recordChecklistItem($task, $i, 'ok', null, $this->operator);
            }
        }

        return $task->fresh();
    }
}
