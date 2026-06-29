<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Pages;

use Andre\AiPageBuilder\Services\Settings;
use Andre\AiPageBuilder\Services\Theme;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Edit the global theme tokens (brand colours, fonts, shape). Saved into the
 * `theme` setting; emitted as :root custom properties into every rendered page
 * + the builder canvas, and named in the AI prompt — so the whole site re-skins
 * from this one screen.
 */
class ThemeSettings extends FilamentPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected string $view = 'ai-page-builder::filament.pages.settings';

    /** @var array<string,mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return 'Theme';
    }

    public function getTitle(): string
    {
        return 'Theme';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.system', 'System');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 101;
    }

    public function mount(): void
    {
        $this->form->fill(app(Theme::class)->tokens());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Brand colours')
                    ->description('Pages reference these as var(--pb-primary), var(--pb-accent), etc. Change them here to re-skin the whole site.')
                    ->compact()
                    ->columns(3)
                    ->schema([
                        Forms\Components\ColorPicker::make('primary')->label('Primary'),
                        Forms\Components\ColorPicker::make('accent')->label('Accent'),
                        Forms\Components\ColorPicker::make('ink')->label('Text (ink)'),
                        Forms\Components\ColorPicker::make('muted')->label('Muted text'),
                        Forms\Components\ColorPicker::make('bg')->label('Page background'),
                        Forms\Components\ColorPicker::make('surface')->label('Surface (cards)'),
                        Forms\Components\ColorPicker::make('border')->label('Border'),
                    ]),

                Section::make('Typography & shape')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('font')
                            ->label('Body font (CSS font-family)')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('heading_font')
                            ->label('Heading font')
                            ->placeholder('Leave blank to use the body font')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('radius')
                            ->label('Corner radius')
                            ->placeholder('0.75rem'),
                        Forms\Components\TextInput::make('max_width')
                            ->label('Content max-width')
                            ->placeholder('1140px'),
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
                ->label('Save theme')
                ->icon('heroicon-o-check')
                ->action('save'),

            Action::make('reset')
                ->label('Reset to defaults')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(Settings::class)->set('theme', null);
                    $this->form->fill(Theme::DEFAULTS);
                    Notification::make()->success()->title('Theme reset to defaults')->send();
                }),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $tokens = [];
        foreach (array_keys(Theme::DEFAULTS) as $key) {
            $tokens[$key] = (string) ($state[$key] ?? Theme::DEFAULTS[$key]);
        }

        app(Settings::class)->set('theme', $tokens);

        Notification::make()->success()->title('Theme saved')->send();
    }
}
