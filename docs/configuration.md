# Configuration reference

[← Docs index](README.md)

Every key in `config/ai-page-builder.php`, with its env var, default and meaning. Publish the config to override defaults in a file, or set the env vars.

```bash
php artisan vendor:publish --tag="ai-page-builder-config"
```

## `database`

Where the package's own tables live.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `database.connection` | `AI_PAGE_BUILDER_DB_CONNECTION` | `null` (host default) | Connection for all package tables |
| `database.tables.pages` | — | `pages` | Pages + email templates |
| `database.tables.media` | — | `page_builder_media` | Media items |
| `database.tables.flows` | — | `page_builder_flows` | Flow definitions |
| `database.tables.flow_runs` | — | `page_builder_flow_runs` | Flow run telemetry |
| `database.tables.functions` | — | `page_builder_functions` | Functions |
| `database.tables.models` | — | `page_builder_models` | Collection metadata |
| `database.tables.fields` | — | `page_builder_fields` | Collection field metadata |
| `database.tables.variables` | — | `page_builder_variables` | States |
| `database.tables.settings` | — | `page_builder_settings` | Builder settings |
| `database.tables.users` | — | `page_builder_users` | Built-app end-users |
| `database.tables.roles` | — | `page_builder_roles` | Built-app roles |
| `database.tables.permissions` | — | `page_builder_permissions` | Built-app permissions |

## `models`

Swap any model for your own subclass (see [Extending](extending.md#swapping-a-model)).

| Key | Default class |
|---|---|
| `models.page` | `Page::class` |
| `models.media` | `MediaItem::class` |
| `models.flow` | `Flow::class` |
| `models.flow_run` | `FlowRun::class` |
| `models.flow_function` | `FlowFunction::class` |
| `models.model` | `PbModel::class` |
| `models.field` | `PbField::class` |
| `models.variable` | `Variable::class` |
| `models.setting` | `PbSetting::class` |
| `models.user` | `PbUser::class` |
| `models.role` | `PbRole::class` |
| `models.permission` | `PbPermission::class` |

## `data` — the Collections backbone

| Key | Env | Default | Meaning |
|---|---|---|---|
| `data.table_prefix` | `AI_PAGE_BUILDER_DATA_PREFIX` | `pb_` | Prefix for generated collection tables (`pb_<key>`) |
| `data.api_prefix` | `AI_PAGE_BUILDER_DATA_API_PREFIX` | `api/pb` | Auto REST API prefix; empty string disables the API |
| `data.api_middleware` | — | `[EncryptCookies, StartSession, api]` | API middleware — cookie+session so the `pb` end-user is recognised; no CSRF so stateless writes work |
| `data.allow_destructive_sync` | `AI_PAGE_BUILDER_DATA_DESTRUCTIVE` | `false` | Drop real columns when a field is removed. Off so a mis-edit can't destroy data |
| `data.default_per_page` | `AI_PAGE_BUILDER_DATA_PER_PAGE` | `25` | Default API page size |
| `data.max_per_page` | `AI_PAGE_BUILDER_DATA_MAX_PER_PAGE` | `200` | Max API page size (clamp) |

## `flow` — the automation engine

| Key | Env | Default | Meaning |
|---|---|---|---|
| `flow.run_route_enabled` | `AI_PAGE_BUILDER_FLOW_ROUTE` | `true` | Register the public `POST /{flow_prefix}/{slug}` endpoint |
| `flow.rate_limit_per_minute` | `AI_PAGE_BUILDER_FLOW_RATE` | `30` | Global per-flow-per-IP rate limit (a flow can override) |
| `flow.max_steps` | — | `200` | Max node steps per run (loop guard) |
| `flow.drawflow_js` | `AI_PAGE_BUILDER_DRAWFLOW_JS` | jsDelivr CDN | Flow-editor JS URL |
| `flow.drawflow_css` | `AI_PAGE_BUILDER_DRAWFLOW_CSS` | jsDelivr CDN | Flow-editor CSS URL |
| `flow.allow_php_functions` | `AI_PAGE_BUILDER_ALLOW_PHP` | `true` | Allow `php`-runtime Functions to execute raw PHP. Set `false` for less-trusted authors — see [security note](functions-and-states.md#php-runtime--and-the-security-flag) |

## `routes`

| Key | Env | Default | Meaning |
|---|---|---|---|
| `routes.render_enabled` | `AI_PAGE_BUILDER_RENDER_ENABLED` | `true` | Register the public page render route |
| `routes.render_prefix` | `AI_PAGE_BUILDER_RENDER_PREFIX` | `p` | Published-page prefix (`/p/{slug}`) |
| `routes.render_middleware` | — | `['web']` | Middleware for render + auth routes |
| `routes.home_at_root` | `AI_PAGE_BUILDER_HOME_AT_ROOT` | `false` | Also serve the home page at `/` (only if the host has no `/` route) |
| `routes.panel_prefix` | `AI_PAGE_BUILDER_PANEL_PREFIX` | `ai-page-builder` | Authenticated in-panel endpoints (media, lint, ai-chat) |
| `routes.panel_middleware` | — | `['web', 'auth']` | Middleware for panel endpoints |
| `routes.flow_prefix` | `AI_PAGE_BUILDER_FLOW_PREFIX` | `pb-flow` | Public flow-run prefix (`/pb-flow/{slug}`) |

## `auth` — the built app's own auth

| Key | Env | Default | Meaning |
|---|---|---|---|
| `auth.enabled` | `AI_PAGE_BUILDER_AUTH` | `true` | Register the `pb` guard, login routes and page gating |
| `auth.guard` | `AI_PAGE_BUILDER_AUTH_GUARD` | `pb` | Guard name for end-users |
| `auth.login_path` | `AI_PAGE_BUILDER_LOGIN_PATH` | `login` | Login page path |
| `auth.redirect_after_login` | `AI_PAGE_BUILDER_AUTH_REDIRECT` | `/` | Post-login redirect / default home |

## `media`

| Key | Env | Default | Meaning |
|---|---|---|---|
| `media.disk` | `AI_PAGE_BUILDER_MEDIA_DISK` | `public` | Filesystem disk (must be publicly accessible) |
| `media.directory` | `AI_PAGE_BUILDER_MEDIA_DIR` | `page-builder` | Directory within the disk |
| `media.accept` | — | `[png, jpeg, gif, webp, svg+xml]` | Accepted MIME types |
| `media.max_kb` | `AI_PAGE_BUILDER_MEDIA_MAX_KB` | `8192` | Max upload size (KB) |

> `media.disk` is the **fallback** disk. When a cloud driver is configured on the Settings → Storage tab (S3 / Azure Blob / GCS), media goes to the runtime `pb-cloud` disk instead — see [Cloud media storage](cloud-storage.md).

## `cache` — render cache

| Key | Env | Default | Meaning |
|---|---|---|---|
| `cache.store` | `AI_PAGE_BUILDER_CACHE_STORE` | `null` (default store) | Cache store for rendered pages |
| `cache.ttl` | `AI_PAGE_BUILDER_CACHE_TTL` | `3600` | Rendered-page TTL (seconds); `0` disables caching |
| `cache.prefix` | — | `ai-page-builder:rendered:` | Cache key prefix |

## `assets` — editor / runtime front-end

| Key | Env | Default | Meaning |
|---|---|---|---|
| `assets.grapesjs_css` | `AI_PAGE_BUILDER_GRAPESJS_CSS` | unpkg CDN | GrapesJS stylesheet |
| `assets.grapesjs_js` | `AI_PAGE_BUILDER_GRAPESJS_JS` | unpkg CDN | GrapesJS script |
| `assets.alpine_js` | `AI_PAGE_BUILDER_ALPINE_JS` | jsDelivr CDN | Alpine (powers the published-page store) |

(Drawflow asset URLs live under `flow.*`; Ace under `editor.*`.) See [Installation → self-hosting](installation.md#6-self-hosting-front-end-assets-offline--strict-csp).

## `ai`

| Key | Env | Default | Meaning |
|---|---|---|---|
| `ai.driver` | `AI_PAGE_BUILDER_AI_DRIVER` | `auto` | `auto` (gateway → openrouter → null) \| `gateway` \| `openrouter` |
| `ai.default_model` | `AI_PAGE_BUILDER_AI_MODEL` | `anthropic/claude-sonnet-4` | Default model |
| `ai.auto_seed` | `AI_PAGE_BUILDER_AI_AUTO_SEED` | `true` | Auto-seed the gateway integrations on boot |
| `ai.gateway_slug` | — | `page_builder` | Slug of the general gateway integration |
| `ai.app_builder_slug` | — | `app_builder` | Slug of the bundled, self-healing app-generation integration |
| `ai.openrouter.api_key` | `AI_PAGE_BUILDER_OPENROUTER_KEY` / `OPENROUTER_API_KEY` | `null` | Key for the direct OpenRouter driver |
| `ai.openrouter.base_url` | `AI_PAGE_BUILDER_OPENROUTER_URL` | `https://openrouter.ai/api/v1` | OpenRouter base URL |

## `editor` — Ace code editor

| Key | Env | Default | Meaning |
|---|---|---|---|
| `editor.ace_base` | `AI_PAGE_BUILDER_ACE_BASE` | jsDelivr CDN (`ace-builds .../src-min-noconflict`) | Base **directory** for Ace; it lazy-loads `ace.js` + mode/theme/worker files relative to it |

## `filament`

| Key | Default | Meaning |
|---|---|---|
| `filament.navigation_group` | `Content` | Navigation group for all resources/pages |
| `filament.navigation_sort` | `10` | Base navigation sort (resources offset from it) |
| `filament.max_content_width` | `'full'` | Content width for every Synapse admin page. Accepts any Filament width value (`'xl'`, `'2xl'`, `'full'`, etc.) or `null` to fall back to the host panel's default. |

## `uploads`

Controls the public image upload endpoint (`POST /pb-upload`) used by generated forms.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `uploads.allow_anonymous` | `AI_PAGE_BUILDER_ALLOW_ANON_UPLOADS` | `false` | Allow unauthenticated uploads. **Off by default** — safe for public sites. Set `true` only when anonymous image submission is intentional. |
| `uploads.max_kb` | `AI_PAGE_BUILDER_UPLOAD_MAX_KB` | `5120` | Maximum upload size in KB (default 5 MB). Requests over the limit return `413`. |

Next: [Extending](extending.md).
