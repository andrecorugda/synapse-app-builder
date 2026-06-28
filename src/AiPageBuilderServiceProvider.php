<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder;

use Andre\AiPageBuilder\Console\RunCronFlowsCommand;
use Andre\AiPageBuilder\Console\SeedPageBuilderIntegrationCommand;
use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;
use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Flow\GatewayAiInvoker;
use Andre\AiPageBuilder\Flow\NodeRegistry;
use Andre\AiPageBuilder\Flow\Nodes\AiInvokeNode;
use Andre\AiPageBuilder\Flow\Nodes\ConditionNode;
use Andre\AiPageBuilder\Flow\Nodes\HttpRequestNode;
use Andre\AiPageBuilder\Flow\Nodes\ResultNode;
use Andre\AiPageBuilder\Flow\Nodes\TriggerNode;
use Andre\AiPageBuilder\Seeders\PageBuilderIntegrationSeeder;
use Andre\AiPageBuilder\Services\MediaLibrary;
use Andre\AiPageBuilder\Services\PageBuilderManager;
use Andre\AiPageBuilder\Services\PageRenderer;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AiPageBuilderServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ai-page-builder')
            ->hasConfigFile('ai-page-builder')
            ->hasViews('ai-page-builder')
            ->hasMigrations([
                'create_pages_table',
                'create_page_builder_media_table',
                'add_custom_css_to_pages_table',
                'create_page_builder_flows_table',
                'create_page_builder_flow_runs_table',
            ])
            ->hasCommand(SeedPageBuilderIntegrationCommand::class)
            ->hasCommand(RunCronFlowsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PageRenderer::class);
        $this->app->singleton(PageBuilderManager::class);
        $this->app->singleton(MediaLibrary::class);

        // Flow engine.
        $this->app->bind(AiInvoker::class, GatewayAiInvoker::class);
        $this->app->singleton(NodeRegistry::class, function ($app): NodeRegistry {
            $registry = new NodeRegistry;
            $registry->register(new TriggerNode);
            $registry->register($app->make(AiInvokeNode::class));
            $registry->register(new HttpRequestNode);
            $registry->register(new ConditionNode);
            $registry->register(new ResultNode);

            return $registry;
        });
        $this->app->singleton(FlowRunner::class);
        $this->app->singleton(FlowManager::class);
    }

    public function packageBooted(): void
    {
        $this->registerRenderRoutes();
        $this->registerPanelRoutes();
        $this->registerFlowRoutes();
        $this->registerFilamentAssets();
        $this->autoSeedGatewayIntegration();
    }

    /**
     * Public, stateless flow-run endpoint (opt-in per flow, rate-limited in the
     * controller). No web/CSRF middleware — it's called from rendered pages.
     */
    private function registerFlowRoutes(): void
    {
        if (! (bool) config('ai-page-builder.flow.run_route_enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => (string) config('ai-page-builder.routes.flow_prefix', 'pb-flow'),
            'middleware' => ['api'],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/flow.php');
        });
    }

    /**
     * Authenticated in-panel endpoints (media upload, …) under the configured
     * panel prefix + middleware (same guard the Filament panel uses).
     */
    private function registerPanelRoutes(): void
    {
        Route::group([
            'prefix' => (string) config('ai-page-builder.routes.panel_prefix', 'ai-page-builder'),
            'middleware' => (array) config('ai-page-builder.routes.panel_middleware', ['web', 'auth']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/panel.php');
        });
    }

    /**
     * Seed the pre-configured `page_builder` integration into the AI OpenRouter
     * Gateway when it is installed. Fully guarded — a missing gateway, an
     * un-migrated gateway, or any version drift must never break boot.
     */
    private function autoSeedGatewayIntegration(): void
    {
        if (! (bool) config('ai-page-builder.ai.auto_seed', true)) {
            return;
        }

        if (! PageBuilderIntegrationSeeder::gatewayInstalled()) {
            return;
        }

        try {
            $this->app->make(PageBuilderIntegrationSeeder::class)->run();
        } catch (\Throwable $e) {
            Log::warning('[ai-page-builder] gateway integration auto-seed skipped: '.$e->getMessage());
        }
    }

    /**
     * Register the public `/{prefix}/{slug}` route that renders published pages.
     * Disable via config('ai-page-builder.routes.render_enabled') to render
     * Page->html / ->css from the host app's own routing instead.
     */
    private function registerRenderRoutes(): void
    {
        if (! (bool) config('ai-page-builder.routes.render_enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => (string) config('ai-page-builder.routes.render_prefix', 'p'),
            'middleware' => (array) config('ai-page-builder.routes.render_middleware', ['web']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Inject the GrapesJS JS/CSS into every Filament panel page via a render
     * hook. It must live in the panel layout, not the field's component view:
     * Livewire does not execute <script> tags rendered inside a component
     * template. The hook name is a string so it resolves across Filament 3/4/5.
     */
    private function registerFilamentAssets(): void
    {
        if (! class_exists(FilamentView::class)) {
            return;
        }

        FilamentView::registerRenderHook(
            'panels::body.end',
            static fn (): string => view('ai-page-builder::filament.grapesjs-assets')->render(),
        );

        // The Drawflow flow-editor library + Alpine component.
        FilamentView::registerRenderHook(
            'panels::body.end',
            static fn (): string => view('ai-page-builder::filament.flow-assets')->render(),
        );
    }
}
