<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\WatcherResource\Pages;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Watcher;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Reactive triggers: bind a collection event to a target flow/function. Each
 * watcher is ONE event → ONE target, so create/update/delete can each run a
 * different flow (the gap the old single-graph collection trigger left open).
 *
 * State watchers (source_type='state') are supported by the model + dispatcher
 * but not yet exposed here — that arrives with the VariableStore hook.
 */
class WatcherResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.watcher', Watcher::class);
    }

    public static function getModelLabel(): string
    {
        return 'watcher';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.automation', 'Automation');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 5;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Watcher')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        // Only collection watchers are authored here for now; the
                        // column exists so state watchers can join later.
                        Forms\Components\Hidden::make('source_type')
                            ->default('collection'),

                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(160)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('source_key')
                            ->label('Collection')
                            ->required()
                            ->searchable()
                            ->options(fn (): array => PbModel::query()->orderBy('name')->pluck('name', 'key')->all())
                            ->helperText('The collection whose records this watcher listens to.'),

                        Forms\Components\Select::make('event')
                            ->label('On event')
                            ->required()
                            ->default('created')
                            ->options([
                                'created' => 'Created',
                                'updated' => 'Updated',
                                'deleted' => 'Deleted',
                            ])
                            ->helperText('One event per watcher — add separate watchers to run different flows for create / update / delete.'),

                        Forms\Components\Select::make('target_type')
                            ->label('Run')
                            ->required()
                            ->options([
                                'flow' => 'Flow',
                                'function' => 'Function',
                            ])
                            ->default('flow')
                            ->live()
                            // Clear a now-mismatched target when the type flips.
                            ->afterStateUpdated(fn (Forms\Components\Select $component) => $component
                                ->getContainer()
                                ->getComponent('target_key')
                                ?->state(null)),

                        Forms\Components\Select::make('target_key')
                            ->key('target_key')
                            ->label('Target')
                            ->required()
                            ->searchable()
                            ->options(fn (Get $get): array => self::targetOptions((string) $get('target_type')))
                            ->helperText('The flow or function (by slug) to run when the event fires. '
                                .'The event payload is passed as input: {{ input.record }}, {{ input.old }}, {{ input.event }}.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->inline(false)
                            ->default(true),

                        Forms\Components\Repeater::make('config.criteria')
                            ->label('Criteria (optional)')
                            ->helperText('All rows must match for the watcher to fire. Leave empty to fire on every event.')
                            ->schema([
                                Forms\Components\TextInput::make('field')
                                    ->required()
                                    ->placeholder('field key')
                                    ->helperText('A field key of the collection.'),
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
                            ->default([])
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

                Tables\Columns\TextColumn::make('source_key')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (Model $record): string => $record->source_type.':'.$record->source_key),

                Tables\Columns\TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->placeholder('any'),

                Tables\Columns\TextColumn::make('target')
                    ->label('Runs')
                    ->state(fn (Model $record): string => $record->target_type.':'.$record->target_key)
                    ->color('gray'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),

                Tables\Columns\TextColumn::make('last_fired_at')
                    ->label('Last fired')
                    ->dateTime()
                    ->placeholder('never')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_status')
                    ->label('Last status')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'ok' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
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
            'index' => Pages\ListWatchers::route('/'),
            'create' => Pages\CreateWatcher::route('/create'),
            'edit' => Pages\EditWatcher::route('/{record}/edit'),
        ];
    }

    /**
     * Convert the criteria Repeater's row list (`[{field, op, value}]`) into the
     * `{ field: { op: value } }` shape the dispatcher matches against.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function normalizeConfig(array $data): array
    {
        $rows = $data['config']['criteria'] ?? [];
        $criteria = [];

        foreach ((array) $rows as $row) {
            $field = $row['field'] ?? null;
            $op = $row['op'] ?? 'eq';

            if ($field === null || $field === '') {
                continue;
            }

            $criteria[$field][$op] = $row['value'] ?? null;
        }

        if ($criteria === []) {
            unset($data['config']['criteria']);
        } else {
            $data['config']['criteria'] = $criteria;
        }

        return $data;
    }

    /**
     * Inverse of normalizeConfig: expand stored `{ field: { op: value } }`
     * criteria back into Repeater rows for editing.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function denormalizeConfig(array $data): array
    {
        $criteria = $data['config']['criteria'] ?? [];
        $rows = [];

        foreach ((array) $criteria as $field => $conditions) {
            foreach ((array) $conditions as $op => $value) {
                $rows[] = ['field' => $field, 'op' => $op, 'value' => $value];
            }
        }

        $data['config']['criteria'] = $rows;

        return $data;
    }

    /**
     * Slug => "Name (slug)" options for the chosen target type.
     *
     * @return array<string,string>
     */
    private static function targetOptions(string $targetType): array
    {
        if ($targetType === 'function') {
            /** @var class-string<FlowFunction> $model */
            $model = config('ai-page-builder.models.flow_function', FlowFunction::class);
        } else {
            /** @var class-string<Flow> $model */
            $model = config('ai-page-builder.models.flow', Flow::class);
        }

        return $model::query()
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->mapWithKeys(fn (Model $row): array => [
                $row->slug => sprintf('%s (%s)', $row->name, $row->slug),
            ])
            ->all();
    }
}
