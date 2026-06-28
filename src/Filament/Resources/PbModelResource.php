<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource\Pages;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
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

                Schemas\Components\Section::make('Fields')
                    ->schema([
                        Forms\Components\Repeater::make('fields')
                            ->relationship()
                            ->orderColumn('sort')
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? null)
                            ->collapsible()
                            ->cloneable()
                            ->defaultItems(0)
                            ->addActionLabel('Add field')
                            ->schema([
                                Schemas\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('key')
                                        ->required()
                                        ->maxLength(120)
                                        ->regex('/^[a-z][a-z0-9_]*$/')
                                        ->helperText('Column name.'),

                                    Forms\Components\TextInput::make('label')
                                        ->required()
                                        ->maxLength(160),

                                    Forms\Components\Select::make('type')
                                        ->required()
                                        ->options(collect(FieldType::cases())->mapWithKeys(fn (FieldType $t): array => [$t->value => $t->label()])->all())
                                        ->default(FieldType::String->value)
                                        ->live(),
                                ]),

                                Schemas\Components\Grid::make(2)->schema([
                                    Forms\Components\Toggle::make('options.required')
                                        ->label('Required')
                                        ->default(false),

                                    Forms\Components\Toggle::make('options.unique')
                                        ->label('Unique')
                                        ->default(false),

                                    Forms\Components\TextInput::make('options.default')
                                        ->label('Default value')
                                        ->maxLength(255)
                                        ->nullable(),

                                    Forms\Components\TextInput::make('options.length')
                                        ->label('Max length')
                                        ->integer()
                                        ->minValue(1)
                                        ->maxValue(65535)
                                        ->nullable()
                                        ->visible(fn (Get $get): bool => in_array($get('type'), [
                                            FieldType::String->value,
                                            FieldType::Select->value,
                                        ], true)),
                                ]),

                                Forms\Components\TagsInput::make('options.choices')
                                    ->label('Choices')
                                    ->helperText('Allowed values for this select field.')
                                    ->visible(fn (Get $get): bool => $get('type') === FieldType::Select->value),

                                Forms\Components\Select::make('options.relation_model')
                                    ->label('Related collection')
                                    ->options(fn (): array => static::relationModelOptions())
                                    ->searchable()
                                    ->helperText('The collection this field belongs to (stored as {key}_id).')
                                    ->visible(fn (Get $get): bool => $get('type') === FieldType::Relation->value),
                            ])
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
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('key')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('fields_count')
                    ->label('Fields')
                    ->counts('fields')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('table_name')
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
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
    protected static function relationModelOptions(): array
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        return $modelClass::query()
            ->orderBy('name')
            ->pluck('name', 'key')
            ->all();
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
