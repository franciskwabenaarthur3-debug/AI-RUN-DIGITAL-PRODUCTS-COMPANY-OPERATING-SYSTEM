<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('company-os:recover-stale-runs')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('company-os:expire-approvals')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('company-os:expire-proposals')->hourly()->withoutOverlapping();
Schedule::command('company-os:schedule-maintenance')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('company-os:raise-alerts')->hourly()->withoutOverlapping();
Schedule::command('company-os:generate-reports')->dailyAt('00:30')->withoutOverlapping();
Schedule::command('company-os:heartbeat')->everyFiveMinutes();
Schedule::command('company-os:check-system')->hourlyAt(15)->withoutOverlapping();
Schedule::command('company-os:backup')->dailyAt('02:00')->withoutOverlapping()->when(fn () => config('company_os.backups.enabled'));
Schedule::command('company-os:verify-backup')->weeklyOn(0, '03:00')->withoutOverlapping()->when(fn () => config('company_os.backups.enabled') && filled(config('company_os.backups.verify_database')));
