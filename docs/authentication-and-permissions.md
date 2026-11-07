# Authentication & permissions

[← Docs index](README.md)

Synapse ships the **built app's own** identity layer — end-users, roles and permissions — kept entirely separate from your host app's auth and from the Filament admin login. A public website ignores it; an internal tool turns on per-page login and assigns roles. Three tiers exist, and they do not overlap:

| Tier | Who | Where |
|---|---|---|
| **Host app users** | Your Laravel app's own users | Host `web`/`auth` guards |
| **Builder admins** | People who build apps in Filament | Filament panel guard |
| **Built-app end-users** | The users of the app you publish | The **`pb`** guard (this page) |

## Builder admins — who can open Synapse

**Nobody, until you say so.** Since 2.0, every Synapse screen in your Filament panel (Pages, Flows, Functions, Collections, App Users, Credentials, API Tokens, Settings, …) and every in-panel endpoint (the AI chat, media upload, PHP lint) is **denied by default**. Being allowed into your Filament panel is not enough on its own: you decide who builds apps.

### Grant access — one line

Define the `synapse.admin` ability in your `AppServiceProvider` (or `AuthServiceProvider`) `boot()` method:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('synapse.admin', fn ($user) => $user->is_admin);
```

Use whatever fits your app — a column, a role, a permission:

```php
Gate::define('synapse.admin', fn ($user) => $user->hasRole('developer'));      // spatie/laravel-permission
Gate::define('synapse.admin', fn ($user) => in_array($user->email, ['me@acme.test'], true));
```

A `Gate::before()` callback you already have (for example "super admins can do everything") applies too.

### Or decide on the plugin

If you prefer to keep it next to the panel, pass a callback to the plugin instead. It replaces the `synapse.admin` check for that panel:

```php
$panel->plugin(
    AiPageBuilderPlugin::make()->authorize(fn ($user) => $user->is_admin),
);
```

### Finer abilities for sensitive areas

Some areas can hand out secrets, user accounts or server-side code. Each has its own ability. **If you don't define one, it simply follows `synapse.admin`** — so the one line above is all most apps need. Define one when only some of your builders should have it:

| Ability | Covers |
|---|---|
| `synapse.manage-secrets` | Credentials, API tokens |
| `synapse.manage-users` | App users, roles and permissions, invites |
| `synapse.manage-code` | Functions, app import, AI-chat plans that add a **PHP** function |
| `synapse.manage-settings` | Settings, Theme, app export |

```php
Gate::define('synapse.admin', fn ($user) => $user->is_staff);
Gate::define('synapse.manage-secrets', fn ($user) => $user->is_owner);
```

A finer ability can only **narrow** access: a user still needs `synapse.admin` (or the plugin callback) first.

### Your own policies still apply

The Synapse check comes first; then Filament runs your model policies as usual. If you register a policy for a Synapse model (for example `Page`), it can still deny actions for a granted builder.

### Renaming the abilities

Already have permission names you want to reuse? Map them in `config/ai-page-builder.php`:

```php
'filament' => [
    'ability' => 'synapse.admin',                 // the main ability
    'abilities' => [
        'manage-secrets' => 'synapse.manage-secrets',
        'manage-users' => 'synapse.manage-users',
        'manage-code' => 'synapse.manage-code',
        'manage-settings' => 'synapse.manage-settings',
    ],
    'panel' => null,                              // see below
],
```

### The in-panel endpoints

The AI chat (`/ai-page-builder/ai-chat`, `/ai-chat/apply`), media upload and PHP lint routes run behind the `EnsureSynapseAdmin` middleware. It answers **403** unless the signed-in user is a Filament user that your panel lets in (`FilamentUser::canAccessPanel()`) **and** passes `synapse.admin` (or the plugin callback). It checks the panel(s) that register `AiPageBuilderPlugin`; set `filament.panel` (or `AI_PAGE_BUILDER_PANEL`) to a panel id to pin one. Without Filament installed, these endpoints are always refused.

The middleware is always added, even if your published config still lists only `['web', 'auth']` under `routes.panel_middleware`. The AI chat routes are also rate-limited (30 requests a minute).

## The `pb` guard

When `auth.enabled` is true (default), the package registers — without disturbing host auth — a session guard named `pb` (configurable via `auth.guard`) backed by an Eloquent provider on `PbUser` (`page_builder_users`). It is registered idempotently: an existing guard/provider of the same name is left untouched (`registerAuthGuard()`).

### Models

- **`PbUser`** (`page_builder_users`): `name`, `email`, `password` (hashed), `role_id`, `is_active`. `role()` → `PbRole`; `isAdmin()` returns `(bool) $this->role?->is_admin`.
- **`PbRole`** (`page_builder_roles`): `name`, `slug` (the stable identifier used in permission checks and `data-pb-roles`), `description`, `is_admin`. Has `permissions()` and `users()`.
- **`PbPermission`** (`page_builder_permissions`): `role_id`, `resource_type`, `resource_key`, `action`, `rule` (array).

Manage them in the admin under **App Users** and **Roles** (permissions are a relation manager on a role).

## Login & session

`AuthController` + the static, brandable login view (`resources/views/auth/login.blade.php`):

| Route | Method | Behavior |
|---|---|---|
| `/{auth.login_path}` (default `/login`) | `GET` | Show the login form |
| `/{auth.login_path}` | `POST` | Validate email/password, attempt the `pb` guard with `is_active: true` (deactivated users can't log in), regenerate session, redirect to `auth.redirect_after_login` (default `/`) or the intended URL |
| `/pb-logout` | `POST` | Log out the `pb` guard, invalidate session |
| `/pb-auth/me` | `GET` | Return the current end-user as JSON for the page runtime |

`GET /pb-auth/me` returns `{ "user": null }` when signed out, or `{ "user": { "id", "name", "role": "<slug>", "is_admin": <bool> } }`. The published page places this at `$store.app.$user`.

## Page gating with `requires_auth`

A page with `requires_auth = true` is gated by `RenderPageController`: an unauthenticated visitor is redirected to the login path with `intended` (only when `auth.enabled`). Set it per page in the Pages form. See [Pages](pages-and-components.md#the-render-route).

## The permission model — opt-in

`src/Services/AccessControl.php`. The model is deliberately **opt-in / unrestricted-by-default**:

1. **Unrestricted by default** — a resource (collection or page) with **no** permission rows is open to everyone. A brand-new app needs no permission setup.
2. **First rule gates it** — the moment **any** role defines a permission targeting a resource, that resource becomes *restricted*: only granted (role × action) combinations pass.
3. **`is_admin` bypasses everything** — a user whose role has `is_admin = true` skips all checks.

### Abilities & resource types

- `resource_type`: `collection` or `page`.
- `resource_key`: a specific key/slug, or `*` (wildcard — applies to all resources of that type).
- `action`: for collections `read` \| `create` \| `update` \| `delete`; for pages `view`; or `*` (all actions).

`AccessControl::can($user, $action, $type, $key)` resolves to true when auth is disabled, the resource is unrestricted, or the user is admin; otherwise it requires a matching permission row (`role_id` = the user's role, `resource_type` matches, `resource_key IN (key, '*')`, `action IN (action, '*')`). `isRestricted($type, $key)` reports whether any rule targets the resource.

## Row-level rules with `$CURRENT_USER`

A permission row may carry a `rule` (JSON object) that narrows which **rows** a role may touch — row-level security. The literal token `$CURRENT_USER` is replaced with the acting user's id:

```json
{
  "role_id": 5,
  "resource_type": "collection",
  "resource_key": "leads",
  "action": "read",
  "rule": { "owner_id": "$CURRENT_USER", "status": "active" }
}
```

`AccessControl::rowRule($user, $collectionKey, $action)` returns the resolved rule (`$CURRENT_USER` → the user id), or an empty array when there's nothing to enforce (auth off, no user, admin, or unrestricted). `recordMatchesRule($record, $rule)` checks a single row against it.

### How the data API enforces it

`RecordApiController` applies `AccessControl` on every verb:

- **`index` / `show`** — gates `read`, then merges the row rule into the query `filter` (so a user only ever lists/reads rows the rule allows). A `show` that resolves a row failing the rule returns 404.
- **`store`** — gates `create`, then **stamps ownership**: a create rule like `{ "owner_id": "$CURRENT_USER" }` sets that field to the user's id on the new row automatically.
- **`update` / `destroy`** — gates the action, fetches the row, and refuses if it doesn't match the row rule.

A denied action returns `403`. Because flows/functions/the admin call `RecordQuery` directly (trusted paths), the row-level gate is an **edge** control at the public API; design accordingly.

## Component visibility on the page

Beyond gating whole pages, individual elements can show/hide by auth state. The page runtime reads `$store.app.$user` (from `/pb-auth/me`) and applies:

| Attribute | Effect |
|---|---|
| `data-pb-auth` | Hide unless an end-user is logged in |
| `data-pb-roles="editor,admin"` | Hide unless the user's role slug is in the list (admins always pass) |

You can also bind directly, e.g. `x-show="$store.app.$user"` or `x-text="$store.app.$user?.name"`. See [Pages → runtime attributes](pages-and-components.md#runtime-data-attributes).

> Component visibility is a **UX** control (the element is hidden client-side). Data is protected server-side by the data-API enforcement above — always back a hidden control with a collection permission rule.

Next: [Email](email.md).
