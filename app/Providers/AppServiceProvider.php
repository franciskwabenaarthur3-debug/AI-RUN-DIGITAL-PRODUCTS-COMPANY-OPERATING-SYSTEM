<?php

namespace App\Providers;

use App\Agents\Runtime\LlmRuntime;
use App\Agents\Runtime\MockRuntime;
use App\Agents\Runtime\RuntimeRegistry;
use App\Agents\Tools\Internal\CreateLeadTool;
use App\Agents\Tools\Internal\CreateProjectTaskTool;
use App\Agents\Tools\Internal\DraftOutreachTool;
use App\Agents\Tools\Internal\DraftProposalTool;
use App\Agents\Tools\Internal\RequestApprovalTool;
use App\Agents\Tools\Internal\UpdateLeadStatusTool;
use App\Agents\Tools\Internal\ValidateProposalTool;
use App\Agents\Tools\ToolRegistry;
use App\Ai\ModelCatalog;
use App\Ai\Providers\AnthropicProvider;
use App\Events\ApprovalDecided;
use App\Services\ProposalService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuntimeRegistry::class, function ($app) {
            $registry = new RuntimeRegistry(['mock' => new MockRuntime]);

            // The real runtime exists only when a provider key is configured.
            $providers = array_filter([
                'anthropic' => filled(config('services.anthropic.key'))
                    ? new AnthropicProvider(config('services.anthropic.key'), config('services.anthropic.base_url'), (int) config('company_os.ai.max_retries'))
                    : null,
            ]);
            if ($providers !== []) {
                $registry->register(new LlmRuntime($app->make(ModelCatalog::class), $providers));
            }

            return $registry;
        });

        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry([
            $app->make(CreateLeadTool::class),
            $app->make(UpdateLeadStatusTool::class),
            $app->make(CreateProjectTaskTool::class),
            $app->make(RequestApprovalTool::class),
            $app->make(DraftOutreachTool::class),
            $app->make(DraftProposalTool::class),
            $app->make(ValidateProposalTool::class),
        ]));
    }

    public function boot(): void
    {
        Event::listen(ApprovalDecided::class, [ProposalService::class, 'onApprovalDecided']);
    }
}
