<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder;

use Andre\AiPageBuilder\Ai\AppBuilderService;
use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Console\RunCronFlowsCommand;
use Andre\AiPageBuilder\Console\SeedPageBuilderIntegrationCommand;
use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;
use Andre\AiPageBuilder\Flow\FlowDispatcher;
use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Flow\GatewayAiInvoker;
use Andre\AiPageBuilder\Flow\NodeRegistry;
use Andre\AiPageBuilder\Flow\Nodes\AiInvokeNode;
use Andre\AiPageBuilder\Flow\Nodes\ConditionNode;
use Andre\AiPageBuilder\Flow\Nodes\FunctionNode;
use Andre\AiPageBuilder\Flow\Nodes\HttpRequestNode;
use Andre\AiPageBuilder\Flow\Nodes\RecordNode;
use Andre\AiPageBuilder\Flow\Nodes\ResultNode;
use Andre\AiPageBuilder\Flow\Nodes\SendEmailNode;
use Andre\AiPageBuilder\Flow\Nodes\SetVariableNode;
use Andre\AiPageBuilder\Flow\Nodes\TriggerNode;
use Andre\AiPageBuilder\Flow\RecordObserver;
use Andre\AiPageBuilder\Http\Controllers\AuthController;
use Andre\AiPageBuilder\Http\Controllers\RenderPageController;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Seeders\AppBuilderIntegrationSeeder;
use Andre\AiPageBuilder\Seeders\PageBuilderIntegrationSeeder;
use Andre\AiPageBuilder\Services\AccessControl;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Andre\AiPageBuilder\Services\MediaLibrary;
use Andre\AiPageBuilder\Services\PageBuilderManager;
use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Services\Settings;
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
                'add_custom_js_to_pages_table',
                'create_page_builder_flows_table',
                'create_page_builder_flow_runs_table',
                'create_page_builder_functions_table',
                'create_page_builder_models_table',
                'create_page_builder_fields_table',
                'create_page_builder_variables_table',
                'create_page_builder_settings_table',
                'add_kind_to_pages_table',
                'create_page_builder_roles_table',
                'create_page_builder_users_table',
                'create_page_builder_permissions_table',
                'add_requires_auth_to_pages_table',
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
        $this->app->singleton(FunctionRegistry::class);
        $this->app->singleton(NodeRegistry::class, function ($app): NodeRegistry {
            $registry = new NodeRegistry;
            $registry->register(new TriggerNode);
            $registry->register($app->make(AiInvokeNode::class));
            $registry->register(new HttpRequestNode);
            $registry->register(new ConditionNode);
            $registry->register(new ResultNode);
            $registry->register($app->make(FunctionNode::class));
            $registry->register($app->make(RecordNode::class));
            $registry->register($app->make(SetVariableNode::class));
            $registry->register($app->make(SendEmailNode::class));

            return $registry;
        });
        $this->app->singleton(FlowRunner::class);
        $this->app->singleton(FlowManager::class);
        $this->app->singleton(FlowDispatcher::class);

        // Data layer (user-defined models / collections).
        $this->app->singleton(SchemaSynchronizer::class);
        $this->app->singleton(RecordQuery::class);
        $this->app->singleton(VariableStore::class);

        // Builder configuration (home page, email/SMTP transport, …).
        $this->app->singleton(Settings::class);

        // End-user permission engine (built-app auth).
        $this->app->singleton(AccessControl::class);

        // AI app builder (gateway-backed; emits a validated Build Plan).
        $this->app->singleton(AppBuilderService::class);
        $this->app->singleton(BuildPlanApplier::class);
    }

    public function packageBooted(): void
    {
        $this->registerAuthGuard();
        $this->registerAuthRoutes();
        $this->registerRenderRoutes();
        $this->registerPanelRoutes();
        $this->registerFlowRoutes();
        $this->registerDataApiRoutes();
        $this->registerFilamentAssets();
        $this->autoSeedGatewayIntegration();
        $this->registerRecordObserver();
        $this->registerPublishableAssets();
    }

    /**
     * Make the vendored front-end libraries (GrapesJS, Drawflow, Alpine, Ace)
     * publishable for offline / air-gapped installs. Publishing copies them to
     * `public/vendor/ai-page-builder`; point the asset config keys at those
     * paths (see config/ai-page-builder.php) to self-host instead of the CDN
     * defaults. Guarded behind console like the package's other publishes.
     */
    private function registerPublishableAssets(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/ai-page-builder'),
        ], 'ai-page-builder-assets');
    }

    /**
     * Wire collection-event flow triggers: observe the dynamic Record model so
     * every collection write fans out to matching `collection`-triggered flows
     * via FlowDispatcher. All record writes go through Record::for(...), so a
     * single observer on the base class covers every collection.
     *
     * Registered here in packageBooted (which runs once per app boot) rather
     * than behind a process-level static guard: Eloquent ties observers to the
     * model's event dispatcher, and a rebuilt application (e.g. between tests)
     * gets a fresh dispatcher — so the observer must re-attach on each boot.
     */
    private function registerRecordObserver(): void
    {
        Record::observe(RecordObserver::class);
    }

    /**
     * Auto REST API for user-defined data models: GET/POST/PATCH/DELETE under
     * `{data.api_prefix}/{model}`. Directus-style, permission-gated in the
     * controller. Disable by clearing the api prefix.
     */
    private function registerDataApiRoutes(): void
    {
        $prefix = (string) config('ai-page-builder.data.api_prefix', 'api/pb');

        if ($prefix === '') {
            return;
        }

        Route::group([
            'prefix' => $prefix,
            'middleware' => (array) config('ai-page-builder.data.api_middleware', ['api']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
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
            // Self-healing: re-seeds the bundled app_builder integration if it
            // was deleted, so the AI app-builder feature can't be broken by
            // removing it. Idempotent; never clobbers a tuned prompt version.
            $this->app->make(AppBuilderIntegrationSeeder::class)->run();
        } catch (\Throwable $e) {
            Log::warning('[ai-page-builder] gateway integration auto-seed skipped: '.$e->getMessage());
        }
    }

    /**
     * Register the package's own session guard (default `pb`) + Eloquent user
     * provider for the BUILT app's end-users — without disturbing the host
     * app's auth config. Idempotent and respectful of host overrides: an
     * existing guard/provider of the same name is left untouched.
     */
    private function registerAuthGuard(): void
    {
        if (! (bool) config('ai-page-builder.auth.enabled', true)) {
            return;
        }

        $guard = (string) config('ai-page-builder.auth.guard', 'pb');
        $provider = 'ai_page_builder_users';

        if (config("auth.guards.{$guard}") === null) {
            config(["auth.guards.{$guard}" => ['driver' => 'session', 'provider' => $provider]]);
        }

        if (config("auth.providers.{$provider}") === null) {
            config(["auth.providers.{$provider}" => [
                'driver' => 'eloquent',
                'model' => config('ai-page-builder.models.user', PbUser::class),
            ]]);
        }
    }

    /**
     * Register the static login / logout routes for the built app's end-users.
     * Path is config-driven (auth.login_path); runs through the render
     * middleware (web) so sessions work. Opt-out by disabling auth.
     */
    private function registerAuthRoutes(): void
    {
        if (! (bool) config('ai-page-builder.auth.enabled', true)) {
            return;
        }

        $login = trim((string) config('ai-page-builder.auth.login_path', 'login'), '/');

        Route::group([
            'middleware' => (array) config('ai-page-builder.routes.render_middleware', ['web']),
        ], function () use ($login): void {
            Route::get($login, [AuthController::class, 'show'])->name('ai-page-builder.login');
            Route::post($login, [AuthController::class, 'login']);
            Route::post('pb-logout', [AuthController::class, 'logout'])->name('ai-page-builder.logout');
        });
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

        // Opt-in: also serve the configured home page at the site root `/`.
        // Off by default so the package never shadows the host app's own home
        // route — enable via config('ai-page-builder.routes.home_at_root').
        if ((bool) config('ai-page-builder.routes.home_at_root', false)) {
            Route::group([
                'middleware' => (array) config('ai-page-builder.routes.render_middleware', ['web']),
            ], function (): void {
                Route::get('/', [RenderPageController::class, 'home'])
                    ->name('ai-page-builder.home-root');
            });
        }
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

        // The Ace code editor + Alpine component.
        FilamentView::registerRenderHook(
            'panels::body.end',
            static fn (): string => view('ai-page-builder::filament.codeeditor-assets')->render(),
        );
    }
}
