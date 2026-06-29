<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Forms\Components\CodeField;
use Andre\AiPageBuilder\Filament\Resources\PartialResource\Pages;
use Andre\AiPageBuilder\Models\Partial;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable partials / symbols — named html (+ css) snippets embedded in pages
 * with `<div data-pb-partial="{slug}"></div>` and expanded at render time, so a
 * header/footer is edited once and updates everywhere it's used.
 */
class PartialResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.partial', Partial::class);
    }

    public static function getNavigationLabel(): string
    {
        return 'Partials';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 4;
    }

    public static function getRecordRouteKeyName(): string
    {
        return 'slug';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Partial')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(120)
                            ->regex('/^[a-z][a-z0-9\-_]*$/')
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Model $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText('Embed in a page with: <div data-pb-partial="{slug}"></div>'),

                        CodeField::make('html')
                            ->label('HTML')
                            ->language('html')
                            ->height(320)
                            ->helperText('Markup injected wherever this partial is embedded. Use theme tokens (var(--pb-*)) for brand consistency.')
                            ->columnSpanFull(),

                        CodeField::make('css')
                            ->label('CSS')
                            ->language('css')
                            ->height(200)
                            ->helperText('Optional CSS appended to any page that uses this partial.')
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartials::route('/'),
            'create' => Pages\CreatePartial::route('/create'),
            'edit' => Pages\EditPartial::route('/{record}/edit'),
        ];
    }
}
