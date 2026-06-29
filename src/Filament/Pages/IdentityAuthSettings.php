<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Pages;

use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Services\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Runtime editor for the end-user auth policy (the BUILT app's own users). The
 * admin's choices persist through the Settings service under the same dotted
 * `auth.*` keys that Auth\AuthSettings reads, so the screen and the resolver
 * stay in lock-step. Install-time config values supply the defaults.
 */
class IdentityAuthSettings extends FilamentPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected string $view = 'ai-page-builder::filament.pages.settings';

    /**
     * Form state (statePath 'data').
     *
     * @var array<string,mixed>
     */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return 'Identity & Auth';
    }

    public function getTitle(): string
    {
        return 'Identity & Auth';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 101;
    }

    public function mount(): void
    {
        $settings = app(Settings::class);

        $this->form->fill([
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
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Sign-in')
                    ->description('How existing end-users authenticate into the built app.')
                    ->compact()
                    ->schema([
                        Toggle::make('password_login')
                            ->label('Allow password sign-in')
                            ->helperText('Turn off for SSO-only apps.'),
                    ]),

                Section::make('Registration')
                    ->description('Self-registration for new end-users and how they are onboarded.')
                    ->compact()
                    ->schema([
                        Toggle::make('registration_enabled')
                            ->label('Enable self-registration')
                            ->live(),
                        Select::make('registration_mode')
                            ->label('Registration mode')
                            ->options([
                                'open' => 'Open (use immediately)',
                                'approval' => 'Approval required',
                                'invite_only' => 'Invite only',
                            ])
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                        Select::make('default_role')
                            ->label('Default role')
                            ->options(fn (): array => $this->roleOptions())
                            ->native(false)
                            ->nullable()
                            ->placeholder('No role')
                            ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                        TagsInput::make('allowed_email_domains')
                            ->label('Allowed email domains')
                            ->helperText('Leave empty to allow any email domain.')
                            ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                        TextInput::make('reset_token_ttl')
                            ->label('Reset token TTL (seconds)')
                            ->numeric()
                            ->default(3600),
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
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(Settings::class);

        $settings->set('auth.password_login', (bool) ($state['password_login'] ?? false));
        $settings->set('auth.registration_enabled', (bool) ($state['registration_enabled'] ?? false));

        $mode = $state['registration_mode'] ?? null;
        $settings->set('auth.registration_mode', is_string($mode) && $mode !== '' ? $mode : 'approval');

        $role = $state['default_role'] ?? null;
        $settings->set('auth.default_role', is_string($role) && $role !== '' ? $role : null);

        $domains = $state['allowed_email_domains'] ?? [];
        $settings->set('auth.allowed_email_domains', is_array($domains) ? array_values($domains) : []);

        $settings->set('auth.reset_token_ttl', (int) ($state['reset_token_ttl'] ?? 3600));

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
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
}
