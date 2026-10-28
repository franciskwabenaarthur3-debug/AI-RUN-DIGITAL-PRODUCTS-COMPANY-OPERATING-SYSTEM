<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('company-os:recover-stale-runs')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('company-os:expire-approvals')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('company-os:expire-proposals')->hourly()->withoutOverlapping();
