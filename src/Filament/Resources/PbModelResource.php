<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\PbModelResource\Pages;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource\RelationManagers\FieldsRelationManager;
use Andre\AiPageBuilder\Models\PbModel;
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
                    ->schema([
                        Schemas\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('key')
                                ->required()
                                ->maxLength(120)
                                ->regex('/^[a-z][a-z0-9_]*$/')
                                ->unique(ignoreRecord: true)
                                ->disabled(fn (string $operation): bool => $operation === 'edit')
                                ->dehydrated()
                                ->helperText('Lowercase letter then letters, numbers, underscores. Becomes the physical table name — immutable after creation.'),

                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->maxLength(160),

                            Forms\Components\TextInput::make('label_singular')
                                ->maxLength(160)
                                ->nullable(),

                            Forms\Components\TextInput::make('label_plural')
                                ->maxLength(160)
                                ->nullable(),

                            Forms\Components\TextInput::make('icon')
                                ->maxLength(160)
                                ->nullable()
                                ->helperText('Heroicon name, e.g. heroicon-o-user.'),
                        ]),

                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->maxLength(500)
                            ->nullable(),

                        Schemas\Components\Grid::make(2)->schema([
                            Forms\Components\Toggle::make('has_timestamps')
                                ->label('Timestamps')
                                ->helperText('Add created_at / updated_at columns.')
                                ->default(true),

                            Forms\Components\Toggle::make('has_soft_deletes')
                                ->label('Soft deletes')
                                ->helperText('Add a deleted_at column.')
                                ->default(false),
                        ]),
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
     * Existing collection keys, for the relation-field target dropdown.
     *
     * @return array<string,string>
     */
    public static function relationModelOptions(): array
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        return $modelClass::query()
            ->orderBy('name')
            ->pluck('name', 'key')
            ->all();
    }

    public static function getRelations(): array
    {
        return [
            FieldsRelationManager::class,
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
