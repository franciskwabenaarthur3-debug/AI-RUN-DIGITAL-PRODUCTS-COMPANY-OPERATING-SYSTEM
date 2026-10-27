<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('company-os:recover-stale-runs')->everyFiveMinutes()->withoutOverlapping();
