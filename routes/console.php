<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('company-os:recover-stale-runs')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('company-os:expire-approvals')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('company-os:expire-proposals')->hourly()->withoutOverlapping();
Schedule::command('company-os:schedule-maintenance')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('company-os:raise-alerts')->hourly()->withoutOverlapping();
Schedule::command('company-os:generate-reports')->dailyAt('00:30')->withoutOverlapping();
