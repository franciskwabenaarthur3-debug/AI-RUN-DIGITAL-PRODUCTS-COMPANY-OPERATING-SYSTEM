<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Monitoring\ReadinessCheck;
use App\Monitoring\SystemHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SystemStatusController extends Controller
{
    public function __invoke(Request $request, SystemHealth $health, ReadinessCheck $readiness): View
    {
        Gate::authorize('viewSystemStatus');
        $founder = Gate::allows('reviewReadiness');

        return view('system.index', [
            'checks' => $health->checks(),
            'backups' => Backup::query()->latest('id')->limit(10)->get(),
            'readiness' => $founder ? $readiness->items() : null,
            'permissions' => $founder ? $readiness->permissions() : null,
        ]);
    }
}
