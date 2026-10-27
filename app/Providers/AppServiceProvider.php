<?php

namespace App\Providers;

use App\Agents\Runtime\MockRuntime;
use App\Agents\Runtime\RuntimeRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuntimeRegistry::class, fn () => new RuntimeRegistry([
            'mock' => new MockRuntime,
        ]));
    }

    public function boot(): void
    {
        //
    }
}
