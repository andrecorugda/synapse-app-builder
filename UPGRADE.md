# Upgrade guide

## Upgrading to 2.0

2.0 is a security release (advisory GHSA-hj3q-8gc4-6mpw). The main breaking change: **Synapse is now closed until you grant access.** The built app's pages, flows, States and data API also got stricter defaults, listed under [Runtime changes](#runtime-changes-pages-flows-states-data-api). Run `php artisan migrate` after upgrading.

### What changes for you

- **The Synapse screens are denied by default.** Before 2.0, anyone who could sign in to your Filament panel could open every Synapse screen — including Credentials, API Tokens, App Users and Settings. From 2.0, a panel user sees no Synapse screen until you grant the `synapse.admin` ability.
- **The in-panel endpoints are denied by default too.** The AI chat (send and apply), media upload and PHP lint routes now require a Filament user your panel lets in **and** `synapse.admin`. Before 2.0, any user signed in on your default guard could call them — even people you keep out of your panel. Anything else gets a 403.
- Adding a **PHP** function through the AI chat also needs `synapse.manage-code` (which follows `synapse.admin` unless you define it).

### What to do

1. Grant access in your `AppServiceProvider::boot()` (adapt the condition to your app):

   ```php
   use Illuminate\Support\Facades\Gate;

   Gate::define('synapse.admin', fn ($user) => $user->is_admin);
   ```

   Or on the plugin: `AiPageBuilderPlugin::make()->authorize(fn ($user) => $user->is_admin)`.

2. Optional: limit sensitive areas to fewer people with `synapse.manage-secrets`, `synapse.manage-users`, `synapse.manage-code` and `synapse.manage-settings`. See [Builder admins — who can open Synapse](docs/authentication-and-permissions.md#builder-admins--who-can-open-synapse).

3. Sign in as a builder and check that the Synapse menu groups are back. If they are missing, your ability does not return `true` for that user.

### If you published the config file

You don't need to change it. An older published `config/ai-page-builder.php` still lists `'panel_middleware' => ['web', 'auth']`; Synapse adds its access check to those routes anyway, so it can't be left out by accident. If you want your copy to match the new defaults, add:

```php
// routes
'panel_middleware' => ['web', 'auth', \Andre\AiPageBuilder\Http\Middleware\EnsureSynapseAdmin::class],

// filament
'ability' => 'synapse.admin',
'abilities' => [
    'manage-secrets' => 'synapse.manage-secrets',
    'manage-users' => 'synapse.manage-users',
    'manage-code' => 'synapse.manage-code',
    'manage-settings' => 'synapse.manage-settings',
],
'panel' => env('AI_PAGE_BUILDER_PANEL'),
```

### Other changes in 2.0

- The credential edit form no longer sends the stored secret to the browser. The field starts empty; leave it blank to keep the current secret, or type a new one to replace it.
- Signing out of the built app (`/pb-logout`) now ends the whole session and issues a new CSRF token. If you are signed in to your Filament panel in the same browser session, you are signed out there too.
- Turning off two-factor authentication now asks for the current password (or, for accounts that sign in only with SSO, a current authenticator or recovery code).
- SSO sign-in now goes through the two-factor challenge when the user has two-factor turned on, like a password sign-in.
- Creating an API token on Filament 5 works again and shows the token once.

### Runtime changes (pages, flows, States, data API)

Run `php artisan migrate`. The new migration also clears the page cache.

1. **States are server-only by default.** Only States marked **Visible to visitors** are sent to pages (`$store.app`). After migrating, every existing State is server-only, so a component bound to one shows nothing until you mark it:
   - `php artisan ai-page-builder:states` lists the States your pages use.
   - `php artisan ai-page-builder:states --referenced` marks every State your pages reference (`$store.app.<key>`, `data-pb-state`, `data-pb-target`); `--public=a,b` marks them by name. You can also use the toggle on the States screen.
   - Never make a secret visible. Move API keys to **Credentials** and use `credential:` on the HTTP Request node.
   - In build plans and exports the flag is `"public": true`; `VariableStore::set()` takes a fourth argument, `?bool $public`.
2. **Visitors can no longer change server-only States in a flow.** A page's live values only replace visible States and page-only keys (cart, form values). `{{ states.x }}`, `state('x')`, dynamic values and a Function's `states` now all return the same value.
3. **Private flows need a signed-in app user.** A flow that isn't **Public** runs only for a signed-in app user whose role may run it, called from your own site; anyone else gets a 404.
   - Switch any flow that anonymous visitors trigger (contact forms, newsletter sign-ups, state watchers on public pages) to **Public**.
   - New permission: resource type `flow`, action `run`. With no permission rows for a flow, any signed-in app user may run it; admins always may.
   - With `auth.enabled = false`, private flows can't be run over HTTP. The flow route now starts the session (`flow.middleware`).
4. **Page view permissions are enforced.** A page with a `page` / `view` permission is served only to roles that have it, and to admins. Guests are sent to sign in; others get the 404 page. This also applies to signed preview links and to the pages used as the 404 or maintenance page. Restricted and sign-in-only pages are left out of `sitemap.xml`.
5. **Flow output is escaped in HTML.** `{{ token }}` values in `setHtml`, modal `html` and `send_email` bodies are HTML-escaped. Use `{{{ token }}}` only for HTML your flow built itself; a flow that puts AI output into `setHtml` needs it, so weigh the risk. `redirect` and `logout` URLs must be `http(s)` or a relative path; anything else is dropped, and `data-pb-redirect` follows the same rule.
6. **send_email:** a recipient the visitor controls contributes only its first address, which must be valid, or nothing is sent.
7. **Data API:**
   - Expanding a user relation returns only `id` and `name` (`data.user_expand_fields`).
   - `PbUser` no longer includes email, `role_id`, two-factor or SSO columns when serialized. `auth_user()` still returns the signed-in user's own email and `role_id`.
   - Field-level read restrictions now also apply to `filter`, `search`, `sort`, `fields` and the aggregate `field` / `group_by`.
   - Row rules may name a relation by its field key or its column. A rule naming an unknown field now **fails closed**: no rows, and a 403 on create.
   - A default limit of 120 requests per minute applies (`data.rate_limit_per_minute`, env `AI_PAGE_BUILDER_DATA_RATE`; `0` turns it off).
8. **API tokens** of suspended, deactivated, pending or deleted users return 401, and suspending, deactivating or deleting a user revokes their tokens. A token whose owner was deleted no longer acts as a full-access token.
9. **Media:** a stored file's extension comes from its real content type. Only images, PDF, MP4/WebM and MP3/OGG/WAV are stored, and only if they are also in `media.accept`; other files get a validation error.
10. **HTTP Request node:** host names that don't resolve are refused, the connection is pinned to the checked addresses, and 100.64.0.0/10, NAT64 and IPv4-mapped IPv6 are blocked too.
11. **New `Content-Security-Policy` header** on rendered pages: `render.content_security_policy`, default `base-uri 'self'; object-src 'none'`.
12. **Credentials are sent only to their allowed hosts.** After `php artisan migrate`, each credential starts with the hosts your existing flows already call with it. Review them in admin → Developer → Credentials → **Allowed hosts**, or run `php artisan ai-page-builder:credential-hosts`; hosts a flow calls that a credential may not go to are listed, and those requests are no longer made (the node returns `null`, status `0`, and a warning is logged). Add one with `ai-page-builder:credential-hosts <key> --add=api.example.com`. Never allow a shared hosting domain such as `*.herokuapp.com` or `*.ngrok.io`.
13. **Write replies of the data API** contain only what the role may read; a row outside the role's read rule comes back as just `{id}`. If a page reads fields from a create/update reply, give the role read access to them.
14. **`set_variable`** updates the page live only for States marked **Visible to visitors**.
15. **`send_email`:** every `{{ token }}` adds at most one address, wherever its value comes from; only addresses you type into the node make a list. To + CC + BCC is capped at 10 (`AI_PAGE_BUILDER_EMAIL_MAX_RECIPIENTS`).
16. **Flow HTML** (`setHtml`, modals, `ui_modal`) loses event handlers, non-http(s) links, any Alpine attribute that contains a token, and `<style>`, `<svg>` and `<math>` elements. Move tokens between tags or into plain attributes, and put styling in the page's CSS. An HTTP Request URL may no longer contain `{` or `}`.
17. **Renaming a restricted page keeps it restricted;** permanently deleting a page removes its permission rows.
18. **If you published the config,** the new keys (`data.user_expand_fields`, `data.rate_limit_per_minute`, `data.external.*`, `flow.middleware`, `flow.email_max_recipients`, `render.content_security_policy`, `filament.fine_abilities_fallback`) use their defaults when missing; add them only to change them.

### External collections and finer abilities

- **External collections are off until you allow them.** List what builders may map in `config/ai-page-builder.php`:

  ```php
  'data' => ['external' => [
      'connections' => ['reporting'],
      'tables' => ['orders', 'reporting' => ['customers']],   // or '*'
      'writable' => false,
  ]],
  ```

  Until a collection's connection and table are listed, opening it answers 403. Synapse's own tables, your sign-in tables (users, password resets, sessions, personal access tokens, Passport, spatie permission tables) and your queue and cache tables can never be mapped. `'*'` allows any plain table name on a listed connection, but never a schema- or database-qualified one; list those in full. External collections are **read-only** everywhere (API, flows, functions, admin screen) unless `data.external.writable` is `true` and the collection's Read-only toggle is off. Creating or changing one needs the new `synapse.manage-data-sources` ability, which follows `synapse.admin` unless you define it.
- **Finer abilities on permission-system hosts.** If `synapse.admin` comes from spatie/laravel-permission (or any `Gate::before` hook) rather than `Gate::define()`, every finer ability (`manage-secrets`, `-users`, `-code`, `-settings`, `-data-sources`) is **denied** unless granted the same way, so create and grant the permissions your builders need. A spatie permission named after a finer ability also narrows access when `synapse.admin` is `Gate::define`d. To require an explicit grant for every finer ability, set `filament.fine_abilities_fallback` to `deny`.
- **AI chat.** The Build with AI dock shows only to Synapse admins. Applying a plan with a `settings` section needs `synapse.manage-settings`; settings that open self-registration or set the default role also need `synapse.manage-users`, in the chat and on the Settings page.

