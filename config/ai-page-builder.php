<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Middleware\EnsureDataApiSameOrigin;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\FlowRun;
use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PageRevision;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbSetting;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\RecordRevision;
use Andre\AiPageBuilder\Models\Variable;
use Andre\AiPageBuilder\Models\Watcher;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;

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
            'page_revisions' => 'page_revisions',
            'media' => 'page_builder_media',
            'flows' => 'page_builder_flows',
            'flow_runs' => 'page_builder_flow_runs',
            'functions' => 'page_builder_functions',
            // Metadata for user-defined data models (the "collections").
            'models' => 'page_builder_models',
            'fields' => 'page_builder_fields',
            // Per-record change history (data revisions) for collection writes.
            'record_revisions' => 'page_builder_record_revisions',
            // Persistent, app-wide global variables.
            'variables' => 'page_builder_variables',
            // Builder configuration (home page, email/SMTP transport, …).
            'settings' => 'page_builder_settings',
            // End-user identity & access for the BUILT app (Synapse's own auth,
            // distinct from the host app's users).
            'users' => 'page_builder_users',
            'roles' => 'page_builder_roles',
            'permissions' => 'page_builder_permissions',
            'password_resets' => 'page_builder_password_resets',
            'user_invites' => 'page_builder_user_invites',
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
        'page_revision' => PageRevision::class,
        'media' => MediaItem::class,
        'flow' => Flow::class,
        'flow_run' => FlowRun::class,
        'flow_function' => FlowFunction::class,
        'model' => PbModel::class,
        'field' => PbField::class,
        'record_revision' => RecordRevision::class,
        'variable' => Variable::class,
        'watcher' => Watcher::class,
        'setting' => PbSetting::class,
        'user' => PbUser::class,
        'role' => PbRole::class,
        'permission' => PbPermission::class,
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
        // Cookie + session are prepended so the built app's logged-in end-user
        // (the `pb` guard) is recognised on same-origin XHR — that's what lets
        // permission + row-level rules apply. EnsureDataApiSameOrigin then guards
        // cookie-authenticated writes against CSRF with an Origin/Referer check
        // (Bearer-token and read requests bypass it), so stateless API writes keep
        // working; unrestricted collections stay fully open.
        'api_middleware' => [
            EncryptCookies::class,
            StartSession::class,
            EnsureDataApiSameOrigin::class,
            'api',
        ],
        // Drop real columns when their field definition is removed. Off by
        // default so a mis-edit can't destroy data; turn on for true sync.
        'allow_destructive_sync' => (bool) env('AI_PAGE_BUILDER_DATA_DESTRUCTIVE', false),
        'default_per_page' => (int) env('AI_PAGE_BUILDER_DATA_PER_PAGE', 25),
        'max_per_page' => (int) env('AI_PAGE_BUILDER_DATA_MAX_PER_PAGE', 200),
        // Snapshot every create/update/delete on a MANAGED collection into the
        // record_revisions table so an admin can browse (and optionally restore)
        // a record's history. External / read-only collections are never
        // snapshotted (the package doesn't own their writes). Turn off to skip
        // history entirely. A revision-write failure never blocks the real write.
        'record_history' => (bool) env('AI_PAGE_BUILDER_RECORD_HISTORY', true),
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
        // Global executed-node budget for a single flow run, shared across nested
        // loop/transaction bodies — bounds runaway loops. The default comfortably
        // covers a Loop at its 10k-iteration ceiling with a small body; a run that
        // overruns this now fails loudly (a Transaction rolls back) rather than
        // silently truncating. Raise for flows looping over very large collections.
        'max_steps' => (int) env('AI_PAGE_BUILDER_FLOW_MAX_STEPS', 100000),
        // CDN by default (zero-config). To self-host, publish the bundled assets
        // and override these — see "Self-hosting front-end assets" at the bottom.
        'drawflow_js' => env('AI_PAGE_BUILDER_DRAWFLOW_JS', 'https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.js'),
        'drawflow_css' => env('AI_PAGE_BUILDER_DRAWFLOW_CSS', 'https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.css'),

        // Allow Functions with the `php` runtime to execute raw PHP via eval().
        // This is arbitrary code execution by design — only the trusted app
        // author should ever be able to create such a function. It is therefore
        // OFF by default (safe-by-default); a self-hosted, single-tenant operator
        // who trusts their function authors opts in with AI_PAGE_BUILDER_ALLOW_PHP=true.
        // When off, a php-runtime function simply returns null.
        'allow_php_functions' => (bool) env('AI_PAGE_BUILDER_ALLOW_PHP', false),

        // SSRF guard for the HTTP Request flow node. When on (default), requests
        // to private / reserved / loopback / link-local addresses — including the
        // cloud metadata endpoint 169.254.169.254 — and non-http(s) schemes are
        // refused, and redirects are not followed. Optionally restrict outbound
        // calls to an explicit comma-separated host allow-list.
        'http_block_private_hosts' => (bool) env('AI_PAGE_BUILDER_HTTP_BLOCK_PRIVATE', true),
        'http_allowed_hosts' => array_values(array_filter(
            explode(',', (string) env('AI_PAGE_BUILDER_HTTP_ALLOWED_HOSTS', '')),
        )),
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

        // Serve the configured home page at the site root (GET /) as well as at
        // the render-prefix root. Opt-in (default off). NOTE: this only takes
        // effect when the host app has NO `/` route of its own — Laravel matches
        // the first-registered route, and a package cannot override the app's
        // own `/`. If your app keeps a `/` route (e.g. the default welcome
        // route), point it at the home controller instead:
        //
        //     use Andre\AiPageBuilder\Http\Controllers\RenderPageController;
        //     Route::get('/', [RenderPageController::class, 'home']);
        //
        // The WHICH page is home is chosen in the Settings screen, not here.
        'home_at_root' => (bool) env('AI_PAGE_BUILDER_HOME_AT_ROOT', false),

        // Authenticated, in-panel endpoints (media upload, etc.). Use the same
        // guard/middleware your Filament panel uses.
        'panel_prefix' => env('AI_PAGE_BUILDER_PANEL_PREFIX', 'ai-page-builder'),
        'panel_middleware' => ['web', 'auth'],

        // Public flow-run endpoint prefix (stateless, rate-limited).
        'flow_prefix' => env('AI_PAGE_BUILDER_FLOW_PREFIX', 'pb-flow'),
    ],

    /*
    |--------------------------------------------------------------------------
    | End-user authentication (the BUILT app's own users)
    |--------------------------------------------------------------------------
    | Synapse ships its OWN identity layer for the app you build — users, roles
    | and permissions kept apart from the host app's auth. It is entirely
    | optional: a public website ignores it; an internal tool turns on
    | "requires login" per page and assigns roles/permissions.
    |
    | The package registers a session guard (default name `pb`) backed by its
    | own users table, the static login page, logout, and the gate middleware
    | that protects pages flagged `requires_auth`.
    */
    'auth' => [
        'enabled' => (bool) env('AI_PAGE_BUILDER_AUTH', true),
        'guard' => env('AI_PAGE_BUILDER_AUTH_GUARD', 'pb'),
        'login_path' => env('AI_PAGE_BUILDER_LOGIN_PATH', 'login'),
        // Where to send a user after login / where "home" is when none given.
        'redirect_after_login' => env('AI_PAGE_BUILDER_AUTH_REDIRECT', '/'),

        // The values below are install-time DEFAULTS. They are overridable at
        // runtime by the admin on the "Identity & Auth" settings screen (stored
        // via the Settings service under the same dotted keys, e.g.
        // `auth.password_login`). Read them through Auth\AuthSettings, never
        // config() directly, so the admin's choice wins.

        // Allow email + password sign-in. Turn off for SSO-only apps.
        'password_login' => (bool) env('AI_PAGE_BUILDER_PASSWORD_LOGIN', true),

        // Self-registration. Disabled by default (safer); the admin opts in and
        // picks how new users are onboarded — it depends on the app's nature.
        'registration' => [
            'enabled' => (bool) env('AI_PAGE_BUILDER_REGISTRATION', false),
            // open         — register and use the app immediately
            // approval     — register, then an admin must approve (status=pending)
            // invite_only  — no public registration; admins send invites (Phase 4)
            'mode' => env('AI_PAGE_BUILDER_REGISTRATION_MODE', 'approval'),
            // Role slug assigned to a newly-registered user (null = no role).
            'default_role' => env('AI_PAGE_BUILDER_REGISTRATION_ROLE'),
            // Optional allow-list of email domains, e.g. ['acme.com']. Empty = any.
            'allowed_email_domains' => array_values(array_filter(
                explode(',', (string) env('AI_PAGE_BUILDER_REGISTRATION_DOMAINS', '')),
            )),
        ],

        // Forgot/reset password token lifetime (seconds) + throttle window.
        'reset' => [
            'token_ttl' => (int) env('AI_PAGE_BUILDER_RESET_TTL', 3600),
        ],

        // SSO providers (optional — requires laravel/socialite, and
        // socialiteproviders/microsoft for the Microsoft driver). Credentials
        // come from env; the per-provider `enabled` flag and the org/domain/
        // tenant restrictions are also runtime-overridable on the Identity &
        // Auth screen. A provider only appears on the login page when it is
        // enabled AND has a client id/secret AND Socialite is installed.
        'providers' => [
            'google' => [
                'enabled' => (bool) env('AI_PAGE_BUILDER_GOOGLE_ENABLED', false),
                'client_id' => env('AI_PAGE_BUILDER_GOOGLE_CLIENT_ID'),
                'client_secret' => env('AI_PAGE_BUILDER_GOOGLE_CLIENT_SECRET'),
                'redirect' => env('AI_PAGE_BUILDER_GOOGLE_REDIRECT'),
                // Restrict to one or more Google Workspace hosted domains, e.g.
                // ['acme.com']. Empty = any Google account.
                'allowed_domains' => array_values(array_filter(
                    explode(',', (string) env('AI_PAGE_BUILDER_GOOGLE_DOMAINS', '')),
                )),
            ],
            'microsoft' => [
                'enabled' => (bool) env('AI_PAGE_BUILDER_MICROSOFT_ENABLED', false),
                'client_id' => env('AI_PAGE_BUILDER_MICROSOFT_CLIENT_ID'),
                'client_secret' => env('AI_PAGE_BUILDER_MICROSOFT_CLIENT_SECRET'),
                'redirect' => env('AI_PAGE_BUILDER_MICROSOFT_REDIRECT'),
                // Restrict to a specific Azure AD tenant id (single-org login).
                'tenant' => env('AI_PAGE_BUILDER_MICROSOFT_TENANT'),
                'allowed_domains' => array_values(array_filter(
                    explode(',', (string) env('AI_PAGE_BUILDER_MICROSOFT_DOMAINS', '')),
                )),
            ],
            'github' => [
                'enabled' => (bool) env('AI_PAGE_BUILDER_GITHUB_ENABLED', false),
                'client_id' => env('AI_PAGE_BUILDER_GITHUB_CLIENT_ID'),
                'client_secret' => env('AI_PAGE_BUILDER_GITHUB_CLIENT_SECRET'),
                'redirect' => env('AI_PAGE_BUILDER_GITHUB_REDIRECT'),
                // Restrict to members of these GitHub orgs, e.g. ['acme-inc'].
                // Empty = any GitHub account (public sign-in).
                'allowed_orgs' => array_values(array_filter(
                    explode(',', (string) env('AI_PAGE_BUILDER_GITHUB_ORGS', '')),
                )),
            ],
        ],

        // Two-factor auth (runtime-overridable on the Identity & Auth screen).
        // 'email' OTP needs no extra package; 'totp' (authenticator app) requires
        // pragmarx/google2fa and is silently dropped from the offered methods when
        // that package is absent.
        'two_factor' => [
            'enabled' => (bool) env('AI_PAGE_BUILDER_2FA', true),
            'methods' => ['totp', 'email'],
        ],
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
        // SVG is intentionally excluded: an .svg can carry <script>/onload and
        // become stored XSS when served inline. Re-add only if you sanitize SVGs.
        'accept' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
        'max_kb' => (int) env('AI_PAGE_BUILDER_MEDIA_MAX_KB', 8192),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public upload endpoint
    |--------------------------------------------------------------------------
    | Controls the behaviour of POST /pb-upload — the public image-upload route
    | used by [data-pb-record] forms on rendered pages.
    |
    | allow_anonymous — false (default, safe): only authenticated users (either
    |   a signed-in pb app-user or a panel/host-app user) may upload. Set to
    |   true ONLY when you have external controls (WAF, IP allow-list, etc.).
    |
    | max_kb — maximum accepted file size in kilobytes (default 5 120 = 5 MB).
    |   Applies regardless of the allow_anonymous setting.
    */
    'uploads' => [
        'allow_anonymous' => (bool) env('AI_PAGE_BUILDER_ALLOW_ANON_UPLOADS', false),
        'max_kb' => (int) env('AI_PAGE_BUILDER_UPLOAD_MAX_KB', 5120),
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
    | dev; publish + self-host for production (override these env vars — see
    | "Self-hosting front-end assets" at the bottom of this file).
    */
    'assets' => [
        'grapesjs_css' => env('AI_PAGE_BUILDER_GRAPESJS_CSS', 'https://unpkg.com/grapesjs/dist/css/grapes.min.css'),
        'grapesjs_js' => env('AI_PAGE_BUILDER_GRAPESJS_JS', 'https://unpkg.com/grapesjs'),
        // Alpine powers the published page's reactive Store (data binding).
        // Self-host by publishing this asset and overriding the URL.
        'alpine_js' => env('AI_PAGE_BUILDER_ALPINE_JS', 'https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js'),
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
        // The bundled, self-healing integration for AI app generation (emits a
        // validated Build Plan). Seeded into the gateway on boot; do not delete.
        'app_builder_slug' => 'app_builder',
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
    | pointing this at the published `ace` folder (Ace lazy-loads ace.js plus
    | its mode/theme/worker files relative to this base, so it must be a
    | directory). See "Self-hosting front-end assets" at the bottom of this file.
    */
    'editor' => [
        'ace_base' => env('AI_PAGE_BUILDER_ACE_BASE', 'https://cdn.jsdelivr.net/npm/ace-builds@1.36.5/src-min-noconflict'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Editor
    |--------------------------------------------------------------------------
    |
    | livewire_max_nesting_depth — the visual editor syncs a nested GrapesJS
    | component tree to Livewire; rich pages nest past Livewire's default of 10
    | (→ MaxNestingDepthExceededException on save). The package raises the host's
    | limit to this floor (never lowers a higher host value).
    */
    'editor' => [
        'livewire_max_nesting_depth' => (int) env('AI_PAGE_BUILDER_LW_MAX_NESTING', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament
    |--------------------------------------------------------------------------
    */
    'filament' => [
        // Legacy single-group fallback (kept for backward compat).
        'navigation_group' => 'Content',
        'navigation_sort' => 10,

        // Content width for every Synapse admin page — wider by default so forms and
        // the flow canvas have room. Any Filament MaxWidth value ('full', 'screen-2xl',
        // '7xl', …); set null to leave the host panel's own width untouched.
        'max_content_width' => env('AI_PAGE_BUILDER_MAX_CONTENT_WIDTH', 'full'),

        // Resources + pages are grouped by purpose for a tidy, scannable menu.
        // Override a label to rename a group, or set several keys to the SAME
        // label to merge groups (e.g. fold everything under one "Synapse" group
        // in a busy host panel). The panel renders them in this order.
        'navigation_groups' => [
            'content' => 'Content',       // pages, partials, media
            'data' => 'Data',             // collections, states
            'automation' => 'Automation', // flows, functions, schedules
            'access' => 'Access',         // users, roles, invites
            'developer' => 'Developer',   // credentials, API tokens, API docs, import/export
            'system' => 'System',         // settings, theme
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-hosting front-end assets (offline / air-gapped installs)
    |--------------------------------------------------------------------------
    | Every front-end library URL above defaults to a public CDN so a fresh
    | install works with zero config. For offline, air-gapped, or strict-CSP
    | deployments, the package bundles vendored copies you can serve yourself.
    |
    | 1. Publish the bundled assets into your app's public directory:
    |
    |        php artisan vendor:publish --tag=ai-page-builder-assets
    |
    |    This copies them to `public/vendor/ai-page-builder/{grapesjs,drawflow,
    |    alpine,ace}/`.
    |
    | 2. Point the asset env vars at the published copies (use asset()/relative
    |    URLs so they honour your APP_URL / scheme):
    |
    |        AI_PAGE_BUILDER_GRAPESJS_JS="/vendor/ai-page-builder/grapesjs/grapes.min.js"
    |        AI_PAGE_BUILDER_GRAPESJS_CSS="/vendor/ai-page-builder/grapesjs/grapes.min.css"
    |        AI_PAGE_BUILDER_DRAWFLOW_JS="/vendor/ai-page-builder/drawflow/drawflow.min.js"
    |        AI_PAGE_BUILDER_DRAWFLOW_CSS="/vendor/ai-page-builder/drawflow/drawflow.min.css"
    |        AI_PAGE_BUILDER_ALPINE_JS="/vendor/ai-page-builder/alpine/cdn.min.js"
    |        AI_PAGE_BUILDER_ACE_BASE="/vendor/ai-page-builder/ace"
    |
    |    (Equivalently, in code: asset('vendor/ai-page-builder/grapesjs/grapes.min.js').
    |    The Ace base must stay a directory — Ace appends /ace.js and lazy-loads
    |    its mode, theme and worker files relative to it.)
    |
    | Re-run the publish (add --force) after upgrading the package to refresh the
    | vendored copies. Leaving the env vars unset keeps the CDN defaults.
    */

];
