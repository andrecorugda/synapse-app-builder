<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Models\Page;

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
    | Filament
    |--------------------------------------------------------------------------
    */
    'filament' => [
        'navigation_group' => 'Content',
        'navigation_sort' => 10,
    ],

];
