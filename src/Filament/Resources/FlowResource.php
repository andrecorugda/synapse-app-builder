<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Forms\Components\FlowCanvasField;
use Andre\AiPageBuilder\Filament\Resources\FlowResource\Pages;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\PbModel;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FlowResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.flow', Flow::class);
    }

    public static function getModelLabel(): string
    {
        return 'flow';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 1;
    }

    /** The slug is the route key (defined in Flow::getRouteKeyName). */
    public static function getRecordRouteKeyName(): string
    {
        return 'slug';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Flow')
                    ->compact()
                    ->schema([
                        // Row 1 — slug · name · active · public
                        Schemas\Components\Grid::make(4)->schema([
                            Forms\Components\TextInput::make('slug')
                                ->required()
                                ->maxLength(200)
                                ->regex('/^[a-z0-9\-_]+$/')
                                ->unique(ignoreRecord: true)
                                ->helperText('Lowercase, numbers, dashes — the route key.'),

                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->maxLength(200),

                            Forms\Components\Toggle::make('is_active')
                                ->label('Active')
                                ->inline(false)
                                ->default(false),

                            Forms\Components\Toggle::make('is_public')
                                ->label('Public')
                                ->inline(false)
                                ->default(false)
                                ->helperText('Allow unauthenticated public trigger.'),
                        ]),

                        // Row 2 — rate limit · trigger type · (collection when applicable)
                        Schemas\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('rate_limit_per_minute')
                                ->label('Rate limit / min')
                                ->numeric()
                                ->nullable()
                                ->minValue(0)
                                ->placeholder('Global default'),

                            Forms\Components\Select::make('trigger_type')
                                ->required()
                                ->live()
                                ->options([
                                    'manual' => 'Manual',
                                    'component' => 'Component',
                                    'form' => 'Form',
                                    'cron' => 'Cron',
                                    'api' => 'API',
                                    'collection' => 'Collection event',
                                ])
                                ->default('manual'),

                            Forms\Components\Select::make('trigger_config.collection')
                                ->label('Collection')
                                ->options(fn (): array => PbModel::query()->orderBy('name')->pluck('name', 'key')->all())
                                ->searchable()
                                ->required(fn (Get $get): bool => $get('trigger_type') === 'collection')
                                ->helperText('Records this flow listens to.')
                                ->visible(fn (Get $get): bool => $get('trigger_type') === 'collection'),
                        ]),

                        // Collection-event details — events + criteria (as-is)
                        Schemas\Components\Group::make([
                            Forms\Components\CheckboxList::make('trigger_config.events')
                                ->label('Events')
                                ->options([
                                    'created' => 'Created',
                                    'updated' => 'Updated',
                                    'deleted' => 'Deleted',
                                ])
                                ->columns(3)
                                ->required(fn (Get $get): bool => $get('trigger_type') === 'collection'),

                            Forms\Components\Repeater::make('trigger_config.criteria')
                                ->label('Criteria (optional)')
                                ->helperText('All rows must match for the flow to fire. Leave empty to fire on every event.')
                                ->schema([
                                    Forms\Components\TextInput::make('field')
                                        ->required(),
                                    Forms\Components\Select::make('op')
                                        ->options([
                                            'eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=',
                                            'lt' => '<', 'lte' => '<=', 'like' => 'contains',
                                            'in' => 'in', 'nin' => 'not in', 'null' => 'is null', 'nnull' => 'is not null',
                                        ])
                                        ->default('eq')
                                        ->required(),
                                    Forms\Components\TextInput::make('value'),
                                ])
                                ->columns(3)
                                ->addActionLabel('Add criterion')
                                ->default([]),
                        ])
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('trigger_type') === 'collection'),
                    ]),

                FlowCanvasField::make('definition')
                    ->label('Flow definition')
                    ->columnSpanFull(),
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

                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('trigger_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'manual' => 'gray',
                        'component' => 'info',
                        'form' => 'success',
                        'cron' => 'warning',
                        'api' => 'primary',
                        'collection' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('trigger_type')
                    ->options([
                        'manual' => 'Manual',
                        'component' => 'Component',
                        'form' => 'Form',
                        'cron' => 'Cron',
                        'api' => 'API',
                        'collection' => 'Collection event',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
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

    /**
     * Convert the criteria Repeater's row list (`[{field, op, value}]`) into the
     * `{ field: { op: value } }` shape FlowDispatcher matches against. No-op for
     * non-collection triggers.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function normalizeTriggerConfig(array $data): array
    {
        if (($data['trigger_type'] ?? null) !== 'collection') {
            return $data;
        }

        $rows = $data['trigger_config']['criteria'] ?? [];
        $criteria = [];

        foreach ((array) $rows as $row) {
            $field = $row['field'] ?? null;
            $op = $row['op'] ?? 'eq';

            if ($field === null || $field === '') {
                continue;
            }

            $criteria[$field][$op] = $row['value'] ?? null;
        }

        $data['trigger_config']['criteria'] = $criteria;

        return $data;
    }

    /**
     * Inverse of normalizeTriggerConfig: expand stored `{ field: { op: value } }`
     * criteria back into Repeater rows for editing.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function denormalizeTriggerConfig(array $data): array
    {
        if (($data['trigger_type'] ?? null) !== 'collection') {
            return $data;
        }

        $criteria = $data['trigger_config']['criteria'] ?? [];
        $rows = [];

        foreach ((array) $criteria as $field => $conditions) {
            foreach ((array) $conditions as $op => $value) {
                $rows[] = ['field' => $field, 'op' => $op, 'value' => $value];
            }
        }

        $data['trigger_config']['criteria'] = $rows;

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlows::route('/'),
            'create' => Pages\CreateFlow::route('/create'),
            'edit' => Pages\EditFlow::route('/{record}/edit'),
        ];
    }
}
