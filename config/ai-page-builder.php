<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\FlowRun;
use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;

return [

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    | Override the connection/table so the package's `pages` table can live
    | wherever the host app wants it.
    */
    'database' => [
        'connection' => env('AI_PAGE_BUILDER_DB_CONNECTION'),
        'tables' => [
            'pages' => 'pages',
            'media' => 'page_builder_media',
            'flows' => 'page_builder_flows',
            'flow_runs' => 'page_builder_flow_runs',
            'functions' => 'page_builder_functions',
            // Metadata for user-defined data models (the "collections").
            'models' => 'page_builder_models',
            'fields' => 'page_builder_fields',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | Swap a model for your own subclass if you need extra behaviour.
    */
    'models' => [
        'page' => Page::class,
        'media' => MediaItem::class,
        'flow' => Flow::class,
        'flow_run' => FlowRun::class,
        'flow_function' => FlowFunction::class,
        'model' => PbModel::class,
        'field' => PbField::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data models (the "collections" backbone)
    |--------------------------------------------------------------------------
    | User-defined models become REAL database tables (Directus-style). Each
    | physical table is named `{table_prefix}{model key}` so generated tables
    | never collide with the host app's own tables. The auto REST API exposes
    | every model at `{api_prefix}/{model}` for Flows, Functions and pages to
    | read/write. Tune destructive-sync + page size here.
    */
    'data' => [
        'table_prefix' => env('AI_PAGE_BUILDER_DATA_PREFIX', 'pb_'),
        'api_prefix' => env('AI_PAGE_BUILDER_DATA_API_PREFIX', 'api/pb'),
        'api_middleware' => ['api'],
        // Drop real columns when their field definition is removed. Off by
        // default so a mis-edit can't destroy data; turn on for true sync.
        'allow_destructive_sync' => (bool) env('AI_PAGE_BUILDER_DATA_DESTRUCTIVE', false),
        'default_per_page' => (int) env('AI_PAGE_BUILDER_DATA_PER_PAGE', 25),
        'max_per_page' => (int) env('AI_PAGE_BUILDER_DATA_MAX_PER_PAGE', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Flow engine
    |--------------------------------------------------------------------------
    | The n8n-style orchestration layer. Public (page) triggering is opt-in per
    | flow and rate-limited.
    */
    'flow' => [
        'run_route_enabled' => env('AI_PAGE_BUILDER_FLOW_ROUTE', true),
        'rate_limit_per_minute' => (int) env('AI_PAGE_BUILDER_FLOW_RATE', 30),
        'max_steps' => 200,
        'drawflow_js' => env('AI_PAGE_BUILDER_DRAWFLOW_JS', 'https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.js'),
        'drawflow_css' => env('AI_PAGE_BUILDER_DRAWFLOW_CSS', 'https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.css'),

        // Allow Functions with the `php` runtime to execute raw PHP. This is a
        // self-hosted, single-tenant app builder — the function author is the app
        // owner — so this is intentional. It DOES run arbitrary PHP; set to false
        // to disable if you ever expose the builder to less-trusted users.
        'allow_php_functions' => (bool) env('AI_PAGE_BUILDER_ALLOW_PHP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Front-end render route
    |--------------------------------------------------------------------------
    | The package ships a `/{slug}` route that renders published pages. Disable
    | it to render Page->html / ->css from your own routing instead.
    */
    'routes' => [
        'render_enabled' => env('AI_PAGE_BUILDER_RENDER_ENABLED', true),
        'render_prefix' => env('AI_PAGE_BUILDER_RENDER_PREFIX', 'p'),
        'render_middleware' => ['web'],

        // Authenticated, in-panel endpoints (media upload, etc.). Use the same
        // guard/middleware your Filament panel uses.
        'panel_prefix' => env('AI_PAGE_BUILDER_PANEL_PREFIX', 'ai-page-builder'),
        'panel_middleware' => ['web', 'auth'],

        // Public flow-run endpoint prefix (stateless, rate-limited).
        'flow_prefix' => env('AI_PAGE_BUILDER_FLOW_PREFIX', 'pb-flow'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media library
    |--------------------------------------------------------------------------
    | Where uploaded media is stored, and upload constraints. `disk` must be a
    | configured Laravel filesystem disk that is publicly accessible.
    */
    'media' => [
        'disk' => env('AI_PAGE_BUILDER_MEDIA_DISK', 'public'),
        'directory' => env('AI_PAGE_BUILDER_MEDIA_DIR', 'page-builder'),
        'accept' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'],
        'max_kb' => (int) env('AI_PAGE_BUILDER_MEDIA_MAX_KB', 8192),
    ],

    /*
    |--------------------------------------------------------------------------
    | Render cache
    |--------------------------------------------------------------------------
    | The assembled HTML for a published page is cached. ttl 0 disables it.
    */
    'cache' => [
        'store' => env('AI_PAGE_BUILDER_CACHE_STORE'),
        'ttl' => (int) env('AI_PAGE_BUILDER_CACHE_TTL', 3600),
        'prefix' => 'ai-page-builder:rendered:',
    ],

    /*
    |--------------------------------------------------------------------------
    | GrapesJS assets
    |--------------------------------------------------------------------------
    | URLs for the editor JS/CSS. Defaults point at a CDN for zero-config local
    | dev; publish + self-host for production (override these).
    */
    'assets' => [
        'grapesjs_css' => env('AI_PAGE_BUILDER_GRAPESJS_CSS', 'https://unpkg.com/grapesjs/dist/css/grapes.min.css'),
        'grapesjs_js' => env('AI_PAGE_BUILDER_GRAPESJS_JS', 'https://unpkg.com/grapesjs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    | driver: 'auto' uses the AI OpenRouter Gateway when installed, else the
    | direct OpenRouter driver, else a NullDriver (manual editing still works).
    | Force with 'gateway' or 'openrouter'.
    */
    'ai' => [
        'driver' => env('AI_PAGE_BUILDER_AI_DRIVER', 'auto'),
        'default_model' => env('AI_PAGE_BUILDER_AI_MODEL', 'anthropic/claude-sonnet-4'),
        'auto_seed' => env('AI_PAGE_BUILDER_AI_AUTO_SEED', true),
        'gateway_slug' => 'page_builder',
        'openrouter' => [
            'api_key' => env('AI_PAGE_BUILDER_OPENROUTER_KEY', env('OPENROUTER_API_KEY')),
            'base_url' => env('AI_PAGE_BUILDER_OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Code editor (Ace)
    |--------------------------------------------------------------------------
    | Base URL for the Ace editor used by code fields (syntax highlighting +
    | server-side `php -l` linting). We use Ace's src-noconflict build: it
    | pollutes no globals (unlike Monaco's AMD loader, which broke Livewire) and
    | renders robustly inside Livewire/wire:ignore fields (unlike CodeMirror 5,
    | which crashed on every keystroke here). Defaults to a CDN; self-host by
    | pointing this at your own copy of ace-builds/src-min-noconflict.
    */
    'editor' => [
        'ace_base' => env('AI_PAGE_BUILDER_ACE_BASE', 'https://cdn.jsdelivr.net/npm/ace-builds@1.36.5/src-min-noconflict'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament
    |--------------------------------------------------------------------------
    */
    'filament' => [
        'navigation_group' => 'Content',
        'navigation_sort' => 10,
    ],

];
