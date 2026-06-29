<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\PbModelResource\Pages;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource\RelationManagers\FieldsRelationManager;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource\RelationManagers\RecordsRelationManager;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PbModelResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.model', PbModel::class);
    }

    public static function getModelLabel(): string
    {
        return 'collection';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 3;
    }

    /** The key is the route key (defined in PbModel::getRouteKeyName). */
    public static function getRecordRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * Collection basics only. Fields are managed in a compact table on the edit
     * page (FieldsRelationManager) so they don't expand into a tall form.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Collection')
                    // Compact (not collapsed): tight padding + a dense 4-column
                    // grid keep the details visible but small, so the
                    // Fields/Records tabs get most of the space.
                    ->compact()
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->maxLength(120)
                            ->regex('/^[a-z][a-z0-9_]*$/')
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated()
                            ->helperText('Lowercase; becomes the table name (immutable).'),

                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(160),

                        Forms\Components\TextInput::make('label_singular')
                            ->maxLength(160)
                            ->nullable(),

                        Forms\Components\TextInput::make('label_plural')
                            ->maxLength(160)
                            ->nullable(),

                        Forms\Components\Select::make('icon')
                            ->options(static::iconOptions())
                            ->searchable()
                            ->allowHtml()
                            ->native(false)
                            ->nullable()
                            ->placeholder('Choose an icon')
                            ->columnSpan(2),

                        Forms\Components\Toggle::make('has_timestamps')
                            ->label('Timestamps')
                            ->inline(false)
                            ->default(true),

                        Forms\Components\Toggle::make('has_soft_deletes')
                            ->label('Soft deletes')
                            ->inline(false)
                            ->default(false),

                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->maxLength(500)
                            ->nullable()
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
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('key')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('fields_count')
                    ->label('Fields')
                    ->counts('fields')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('table_name')
                    ->label('Table')
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->before(fn (PbModel $record): mixed => app(SchemaSynchronizer::class)->dropTable($record)),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->before(function ($records): void {
                            $synchronizer = app(SchemaSynchronizer::class);
                            foreach ($records as $record) {
                                $synchronizer->dropTable($record);
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Accepted heroicons for the collection icon picker. Outline set — a curated
     * list that covers the common data-collection cases without a 600-item list.
     *
     * @var array<int,string>
     */
    protected const ICONS = [
        'heroicon-o-circle-stack', 'heroicon-o-table-cells', 'heroicon-o-rectangle-stack',
        'heroicon-o-document-text', 'heroicon-o-document', 'heroicon-o-folder',
        'heroicon-o-user', 'heroicon-o-users', 'heroicon-o-user-group',
        'heroicon-o-building-office', 'heroicon-o-building-office-2', 'heroicon-o-briefcase',
        'heroicon-o-shopping-cart', 'heroicon-o-shopping-bag', 'heroicon-o-tag', 'heroicon-o-ticket',
        'heroicon-o-banknotes', 'heroicon-o-currency-dollar', 'heroicon-o-credit-card',
        'heroicon-o-calendar', 'heroicon-o-calendar-days', 'heroicon-o-clock',
        'heroicon-o-chart-bar', 'heroicon-o-chart-pie', 'heroicon-o-presentation-chart-line',
        'heroicon-o-envelope', 'heroicon-o-chat-bubble-left-right', 'heroicon-o-phone', 'heroicon-o-bell',
        'heroicon-o-star', 'heroicon-o-heart', 'heroicon-o-bookmark', 'heroicon-o-flag',
        'heroicon-o-map-pin', 'heroicon-o-globe-alt', 'heroicon-o-truck', 'heroicon-o-cube',
        'heroicon-o-cog-6-tooth', 'heroicon-o-wrench-screwdriver', 'heroicon-o-key',
        'heroicon-o-lock-closed', 'heroicon-o-shield-check', 'heroicon-o-clipboard-document-list',
        'heroicon-o-check-circle', 'heroicon-o-inbox', 'heroicon-o-archive-box',
        'heroicon-o-photo', 'heroicon-o-film', 'heroicon-o-musical-note',
        'heroicon-o-academic-cap', 'heroicon-o-beaker', 'heroicon-o-light-bulb',
        'heroicon-o-fire', 'heroicon-o-bolt', 'heroicon-o-gift', 'heroicon-o-home', 'heroicon-o-sparkles',
    ];

    /**
     * Icon-picker options: each label renders the glyph next to its short name
     * (allowHtml). Falls back to the name alone if the svg() helper is absent.
     *
     * @return array<string,string>
     */
    public static function iconOptions(): array
    {
        $options = [];

        foreach (self::ICONS as $icon) {
            $name = str_replace('heroicon-o-', '', $icon);
            // Size the glyph with an inline style (not a Tailwind class) so it is
            // consistent regardless of the surrounding stylesheet, and keep the
            // option on a single line.
            $glyph = function_exists('svg')
                ? svg($icon, '', ['style' => 'width:1.15rem;height:1.15rem;flex:0 0 auto;'])->toHtml()
                : '';

            $options[$icon] = '<span style="display:inline-flex;align-items:center;gap:.5rem;white-space:nowrap;line-height:1.4;">'
                .$glyph.'<span>'.$name.'</span></span>';
        }

        return $options;
    }

    /**
     * Targets for a relation field's "belongs to" dropdown: the app's users
     * table (always offered first — a collection can have several user relations
     * like author / approver / assignee) followed by the existing collections.
     *
     * @return array<string,string>
     */
    public static function relationModelOptions(): array
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        $collections = $modelClass::query()
            ->orderBy('name')
            ->pluck('name', 'key')
            ->all();

        return [PbUser::RELATION_TARGET => 'App users'] + $collections;
    }

    public static function getRelations(): array
    {
        return [
            FieldsRelationManager::class,
            RecordsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPbModels::route('/'),
            'create' => Pages\CreatePbModel::route('/create'),
            'edit' => Pages\EditPbModel::route('/{record}/edit'),
        ];
    }
}
