<?php

namespace App\Providers;

use App\Agents\Runtime\MockRuntime;
use App\Agents\Runtime\RuntimeRegistry;
use App\Agents\Tools\Internal\CreateLeadTool;
use App\Agents\Tools\Internal\CreateProjectTaskTool;
use App\Agents\Tools\Internal\RequestApprovalTool;
use App\Agents\Tools\Internal\UpdateLeadStatusTool;
use App\Agents\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuntimeRegistry::class, fn () => new RuntimeRegistry([
            'mock' => new MockRuntime,
        ]));

        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry([
            $app->make(CreateLeadTool::class),
            $app->make(UpdateLeadStatusTool::class),
            $app->make(CreateProjectTaskTool::class),
            $app->make(RequestApprovalTool::class),
        ]));
    }

    public function boot(): void
    {
        //
    }
}
