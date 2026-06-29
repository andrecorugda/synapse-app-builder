<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Pages;

use Andre\AiPageBuilder\Models\Page;
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
