# Authentication & permissions

[← Docs index](README.md)

Synapse ships the **built app's own** identity layer — end-users, roles and permissions — kept entirely separate from your host app's auth and from the Filament admin login. A public website ignores it; an internal tool turns on per-page login and assigns roles. Three tiers exist, and they do not overlap:

| Tier | Who | Where |
|---|---|---|
| **Host app users** | Your Laravel app's own users | Host `web`/`auth` guards |
| **Builder admins** | People who build apps in Filament | Filament panel guard |
| **Built-app end-users** | The users of the app you publish | The **`pb`** guard (this page) |

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
