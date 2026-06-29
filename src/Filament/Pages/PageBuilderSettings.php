<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Pages;

use Andre\AiPageBuilder\Auth\SocialProviders;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Services\PageBuilderMailer;
use Andre\AiPageBuilder\Services\Settings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

/**
 * Builder configuration screen. Today it picks the home page; it is structured
 * in sections so further config (email/SMTP transport, …) slots in alongside.
 * All values persist through the Settings service (page_builder_settings table).
 */
class PageBuilderSettings extends FilamentPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'ai-page-builder::filament.pages.settings';

    /**
     * Form state (statePath 'data').
     *
     * @var array<string,mixed>
     */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return 'Settings';
    }

    public function getTitle(): string
    {
        return 'Page Builder Settings';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        // Bottom of the group (resources start at navigation_sort).
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 100;
    }

    public function mount(): void
    {
        $settings = app(Settings::class);

        $this->form->fill([
            'home_page' => $settings->get('home_page', 'home'),
            // Site behaviour — maintenance mode + 404/maintenance page pickers.
            'maintenance_mode' => filter_var($settings->get('maintenance_mode', false), FILTER_VALIDATE_BOOLEAN),
            'not_found_page' => $settings->get('not_found_page', 'not-found'),
            'maintenance_page' => $settings->get('maintenance_page', 'maintenance'),
            // Email transport. The password is never echoed back — leave blank
            // to keep the stored one (see save()).
            'mail_host' => $settings->get('mail_host'),
            'mail_port' => $settings->get('mail_port', 587),
            'mail_username' => $settings->get('mail_username'),
            'mail_password' => '',
            'mail_encryption' => $settings->get('mail_encryption', 'tls'),
            'mail_from_address' => $settings->get('mail_from_address'),
            'mail_from_name' => $settings->get('mail_from_name'),
            // Identity & Auth — end-user auth policy for the built app. Keys are
            // the dotted `auth.*` Settings keys that Auth\AuthSettings reads.
            'password_login' => filter_var(
                $settings->get('auth.password_login', config('ai-page-builder.auth.password_login', true)),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'registration_enabled' => filter_var(
                $settings->get('auth.registration_enabled', config('ai-page-builder.auth.registration.enabled', false)),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'registration_mode' => $settings->get(
                'auth.registration_mode',
                config('ai-page-builder.auth.registration.mode', 'approval'),
            ),
            'default_role' => $settings->get(
                'auth.default_role',
                config('ai-page-builder.auth.registration.default_role'),
            ),
            'allowed_email_domains' => $settings->get(
                'auth.allowed_email_domains',
                config('ai-page-builder.auth.registration.allowed_email_domains', []),
            ),
            'reset_token_ttl' => (int) $settings->get(
                'auth.reset_token_ttl',
                config('ai-page-builder.auth.reset.token_ttl', 3600),
            ),
            // Single sign-on (SSO) — per-provider enabled flag + restrictions.
            // Credentials are NOT edited here; they come from env/config.
            'sso_google_enabled' => filter_var(
                $settings->get('auth.providers.google.enabled', config('ai-page-builder.auth.providers.google.enabled', false)),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'sso_google_domains' => $settings->get(
                'auth.providers.google.allowed_domains',
                config('ai-page-builder.auth.providers.google.allowed_domains', []),
            ),
            'sso_microsoft_enabled' => filter_var(
                $settings->get('auth.providers.microsoft.enabled', config('ai-page-builder.auth.providers.microsoft.enabled', false)),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'sso_microsoft_tenant' => $settings->get(
                'auth.providers.microsoft.tenant',
                config('ai-page-builder.auth.providers.microsoft.tenant'),
            ),
            'sso_microsoft_domains' => $settings->get(
                'auth.providers.microsoft.allowed_domains',
                config('ai-page-builder.auth.providers.microsoft.allowed_domains', []),
            ),
            'sso_github_enabled' => filter_var(
                $settings->get('auth.providers.github.enabled', config('ai-page-builder.auth.providers.github.enabled', false)),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'sso_github_orgs' => $settings->get(
                'auth.providers.github.allowed_orgs',
                config('ai-page-builder.auth.providers.github.allowed_orgs', []),
            ),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Home page')
                    ->description('Choose which published page is served as the home page.')
                    ->compact()
                    ->schema([
                        Forms\Components\Select::make('home_page')
                            ->label('Home page')
                            ->options(fn (): array => $this->pageOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder('None — no home page')
                            ->helperText($this->homeHelpText()),

                        Forms\Components\Placeholder::make('home_url')
                            ->label('Will be served at')
                            ->content(fn (Get $get): string => $this->homeUrlPreview($get('home_page'))),
                    ]),

                Section::make('Site behaviour')
                    ->description('Maintenance mode and the pages shown for 404 / downtime. Built-in defaults ship seeded; admins bypass maintenance mode.')
                    ->compact()
                    ->schema([
                        Forms\Components\Toggle::make('maintenance_mode')
                            ->label('Maintenance mode')
                            ->helperText('When on, visitors get the maintenance page (HTTP 503). Signed-in admins still see the live site.'),
                        Forms\Components\Select::make('not_found_page')
                            ->label('404 page')
                            ->options(fn (): array => $this->pageOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder('Built-in default')
                            ->helperText('Shown for unknown / unpublished URLs.'),
                        Forms\Components\Select::make('maintenance_page')
                            ->label('Maintenance page')
                            ->options(fn (): array => $this->pageOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder('Built-in default')
                            ->helperText('Shown to visitors while maintenance mode is on.'),
                    ]),

                Section::make('Email (SMTP)')
                    ->description('Transport used by the "Send Email" flow node. Stored encrypted; isolated from the host app\'s mailer.')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('mail_host')
                            ->label('SMTP host')
                            ->placeholder('smtp.example.com'),
                        Forms\Components\TextInput::make('mail_port')
                            ->label('Port')
                            ->numeric()
                            ->default(587),
                        Forms\Components\TextInput::make('mail_username')
                            ->label('Username')
                            ->autocomplete('off'),
                        Forms\Components\TextInput::make('mail_password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn (): string => app(Settings::class)->has('mail_password') ? '•••••• (leave blank to keep)' : '')
                            ->helperText('Leave blank to keep the current password.'),
                        Forms\Components\Select::make('mail_encryption')
                            ->label('Encryption')
                            ->options(['tls' => 'TLS / STARTTLS', 'ssl' => 'SSL', '' => 'None'])
                            ->default('tls')
                            ->native(false),
                        Forms\Components\TextInput::make('mail_from_address')
                            ->label('From address')
                            ->email()
                            ->placeholder('no-reply@example.com'),
                        Forms\Components\TextInput::make('mail_from_name')
                            ->label('From name')
                            ->placeholder('My App')
                            ->columnSpanFull(),
                    ]),

                Section::make('Sign-in')
                    ->description('How existing end-users authenticate into the built app.')
                    ->compact()
                    ->schema([
                        Forms\Components\Toggle::make('password_login')
                            ->label('Allow password sign-in')
                            ->helperText('Turn off for SSO-only apps.'),
                    ]),

                Section::make('Registration')
                    ->description('Self-registration for new end-users and how they are onboarded.')
                    ->compact()
                    ->schema([
                        Forms\Components\Toggle::make('registration_enabled')
                            ->label('Enable self-registration')
                            ->live(),
                        Forms\Components\Select::make('registration_mode')
                            ->label('Registration mode')
                            ->options([
                                'open' => 'Open (use immediately)',
                                'approval' => 'Approval required',
                                'invite_only' => 'Invite only',
                            ])
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                        Forms\Components\Select::make('default_role')
                            ->label('Default role')
                            ->options(fn (): array => $this->roleOptions())
                            ->native(false)
                            ->nullable()
                            ->placeholder('No role')
                            ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                        Forms\Components\TagsInput::make('allowed_email_domains')
                            ->label('Allowed email domains')
                            ->helperText('Leave empty to allow any email domain.')
                            ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                        Forms\Components\TextInput::make('reset_token_ttl')
                            ->label('Reset token TTL (seconds)')
                            ->numeric()
                            ->default(3600),
                    ]),

                Section::make('Single sign-on (SSO)')
                    ->description('Enable OAuth providers and restrict who may sign in. Credentials come from your .env; only the per-provider toggle and restrictions are edited here.')
                    ->compact()
                    ->schema([
                        Forms\Components\Toggle::make('sso_google_enabled')
                            ->label('Google')
                            ->live(),
                        Forms\Components\Placeholder::make('sso_google_status')
                            ->label('Credentials')
                            ->content(fn (): string => $this->ssoCredentialStatus('google'))
                            ->visible(fn (Get $get): bool => (bool) $get('sso_google_enabled')),
                        Forms\Components\TagsInput::make('sso_google_domains')
                            ->label('Allowed email domains')
                            ->helperText('Restrict to these Google Workspace hosted domains. Empty = any Google account.')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_google_enabled')),

                        Forms\Components\Toggle::make('sso_microsoft_enabled')
                            ->label('Microsoft')
                            ->live(),
                        Forms\Components\Placeholder::make('sso_microsoft_status')
                            ->label('Credentials')
                            ->content(fn (): string => $this->ssoCredentialStatus('microsoft'))
                            ->visible(fn (Get $get): bool => (bool) $get('sso_microsoft_enabled')),
                        Forms\Components\TextInput::make('sso_microsoft_tenant')
                            ->label('Tenant')
                            ->helperText('Restrict to a specific Azure AD tenant id (single-org login). Leave blank for multi-tenant.')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_microsoft_enabled')),
                        Forms\Components\TagsInput::make('sso_microsoft_domains')
                            ->label('Allowed email domains')
                            ->helperText('Empty = any account in the configured tenant.')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_microsoft_enabled')),

                        Forms\Components\Toggle::make('sso_github_enabled')
                            ->label('GitHub')
                            ->live(),
                        Forms\Components\Placeholder::make('sso_github_status')
                            ->label('Credentials')
                            ->content(fn (): string => $this->ssoCredentialStatus('github'))
                            ->visible(fn (Get $get): bool => (bool) $get('sso_github_enabled')),
                        Forms\Components\TagsInput::make('sso_github_orgs')
                            ->label('Allowed organisations')
                            ->helperText('Restrict to members of these GitHub org logins. Empty = any GitHub account.')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_github_enabled')),
                    ]),
            ]);
    }

    /**
     * @return array<int,Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->icon('heroicon-o-check')
                ->action('save'),

            Action::make('sendTest')
                ->label('Send test email')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->visible(fn (): bool => app(PageBuilderMailer::class)->configured())
                ->schema([
                    Forms\Components\TextInput::make('recipient')
                        ->label('Send to')
                        ->email()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(PageBuilderMailer::class)->sendTest((string) $data['recipient']);
                        Notification::make()->success()->title('Test email sent')->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Could not send')->body($e->getMessage())->send();
                    }
                }),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(Settings::class);

        $home = $state['home_page'] ?? null;
        $settings->set('home_page', is_string($home) && $home !== '' ? $home : null);

        // Site behaviour.
        $settings->set('maintenance_mode', (bool) ($state['maintenance_mode'] ?? false));
        foreach (['not_found_page', 'maintenance_page'] as $key) {
            $settings->set($key, isset($state[$key]) && $state[$key] !== '' ? (string) $state[$key] : null);
        }

        // Email transport.
        foreach (['mail_host', 'mail_username', 'mail_encryption', 'mail_from_address', 'mail_from_name'] as $key) {
            $settings->set($key, isset($state[$key]) && $state[$key] !== '' ? (string) $state[$key] : null);
        }
        $settings->set('mail_port', (int) ($state['mail_port'] ?? 587));

        // Only overwrite the password when a new one was entered (blank keeps it).
        $password = (string) ($state['mail_password'] ?? '');
        if ($password !== '') {
            $settings->setEncrypted('mail_password', $password);
        }

        // Identity & Auth — end-user auth policy.
        $settings->set('auth.password_login', (bool) ($state['password_login'] ?? false));
        $settings->set('auth.registration_enabled', (bool) ($state['registration_enabled'] ?? false));

        $mode = $state['registration_mode'] ?? null;
        $settings->set('auth.registration_mode', is_string($mode) && $mode !== '' ? $mode : 'approval');

        $role = $state['default_role'] ?? null;
        $settings->set('auth.default_role', is_string($role) && $role !== '' ? $role : null);

        $domains = $state['allowed_email_domains'] ?? [];
        $settings->set('auth.allowed_email_domains', is_array($domains) ? array_values($domains) : []);

        $settings->set('auth.reset_token_ttl', (int) ($state['reset_token_ttl'] ?? 3600));

        // Single sign-on (SSO) — per-provider enabled flag + restrictions.
        $settings->set('auth.providers.google.enabled', (bool) ($state['sso_google_enabled'] ?? false));
        $googleDomains = $state['sso_google_domains'] ?? [];
        $settings->set('auth.providers.google.allowed_domains', is_array($googleDomains) ? array_values($googleDomains) : []);

        $settings->set('auth.providers.microsoft.enabled', (bool) ($state['sso_microsoft_enabled'] ?? false));
        $tenant = $state['sso_microsoft_tenant'] ?? null;
        $settings->set('auth.providers.microsoft.tenant', is_string($tenant) && $tenant !== '' ? $tenant : null);
        $microsoftDomains = $state['sso_microsoft_domains'] ?? [];
        $settings->set('auth.providers.microsoft.allowed_domains', is_array($microsoftDomains) ? array_values($microsoftDomains) : []);

        $settings->set('auth.providers.github.enabled', (bool) ($state['sso_github_enabled'] ?? false));
        $githubOrgs = $state['sso_github_orgs'] ?? [];
        $settings->set('auth.providers.github.allowed_orgs', is_array($githubOrgs) ? array_values($githubOrgs) : []);

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }

    /**
     * Published pages as slug => "Title (slug)" for the home-page picker.
     *
     * @return array<string,string>
     */
    private function pageOptions(): array
    {
        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);

        return $model::query()
            ->published()
            ->pages() // real pages only — not email templates
            ->orderBy('title')
            ->get(['title', 'slug'])
            ->mapWithKeys(fn (Page $p): array => [$p->slug => $p->title.' ('.$p->slug.')'])
            ->all();
    }

    /**
     * End-user roles as slug => name for the default-role picker.
     *
     * @return array<string,string>
     */
    private function roleOptions(): array
    {
        /** @var class-string<PbRole> $model */
        $model = config('ai-page-builder.models.role', PbRole::class);

        return $model::query()->pluck('name', 'slug')->all();
    }

    /**
     * Human-readable credential status for an SSO provider, resolved through
     * SocialProviders (env/config credentials). Credentials are NOT edited on
     * this screen — they come from the .env.
     */
    private function ssoCredentialStatus(string $provider): string
    {
        if (app(SocialProviders::class)->hasCredentials($provider)) {
            return 'Credentials: configured';
        }

        $env = strtoupper($provider);

        return "Not configured — set AI_PAGE_BUILDER_{$env}_CLIENT_ID / _CLIENT_SECRET in your .env";
    }

    private function homeHelpText(): string
    {
        $prefix = trim((string) config('ai-page-builder.routes.render_prefix', 'p'), '/');

        // The prefix root always works. The SITE root (/) belongs to the host
        // app — a package can't override the app's own `/` route — so serving
        // there needs the host to yield it. Be explicit so nobody expects the
        // env flag alone to win against an existing welcome route.
        return "Always served at /{$prefix}. To serve it at the site root (/), point your app's root route at the home controller — "
            ."Route::get('/', [\\Andre\\AiPageBuilder\\Http\\Controllers\\RenderPageController::class, 'home']); — "
            .'in routes/web.php (remove the default welcome route first). If your app has no own / route, set AI_PAGE_BUILDER_HOME_AT_ROOT=true to register one.';
    }

    private function homeUrlPreview(mixed $slug): string
    {
        if (! is_string($slug) || $slug === '') {
            return '— (no home page set)';
        }

        $prefix = trim((string) config('ai-page-builder.routes.render_prefix', 'p'), '/');
        $atRoot = (bool) config('ai-page-builder.routes.home_at_root', false);

        return $atRoot ? "/{$prefix}  and  /" : "/{$prefix}";
    }
}
