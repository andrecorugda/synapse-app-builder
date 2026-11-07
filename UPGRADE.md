# Upgrade guide

## Upgrading to 2.0

2.0 is a security release (advisory GHSA-hj3q-8gc4-6mpw). It has **one breaking change**: Synapse is now closed until you grant access.

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
