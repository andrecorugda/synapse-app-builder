<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Pages;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\Settings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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
            'home_page' => $settings->get('home_page'),
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

        $home = $state['home_page'] ?? null;
        $settings->set('home_page', is_string($home) && $home !== '' ? $home : null);

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
            ->orderBy('title')
            ->get(['title', 'slug'])
            ->mapWithKeys(fn (Page $p): array => [$p->slug => $p->title.' ('.$p->slug.')'])
            ->all();
    }

    private function homeHelpText(): string
    {
        $prefix = trim((string) config('ai-page-builder.routes.render_prefix', 'p'), '/');
        $atRoot = (bool) config('ai-page-builder.routes.home_at_root', false);

        $base = "Served at /{$prefix}.";
        $base .= $atRoot
            ? ' Also served at the site root / (AI_PAGE_BUILDER_HOME_AT_ROOT is on).'
            : ' To also serve it at the site root /, set AI_PAGE_BUILDER_HOME_AT_ROOT=true.';

        return $base;
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
