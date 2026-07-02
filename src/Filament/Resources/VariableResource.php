<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\VariableResource\Pages;
use Andre\AiPageBuilder\Models\Variable;
use Andre\AiPageBuilder\Services\State\StateShapeService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class VariableResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-variable';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.variable', Variable::class);
    }

    public static function getModelLabel(): string
    {
        return 'state';
    }

    public static function getPluralModelLabel(): string
    {
        return 'States';
    }

    public static function getNavigationLabel(): string
    {
        return 'States';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.data', 'Data');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 3;
    }

    /** The key is the route key (defined in Variable::getRouteKeyName). */
    public static function getRecordRouteKeyName(): string
    {
        return 'key';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('State')
                    ->compact()
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->maxLength(120)
                            ->regex('/^[a-z][a-z0-9_]*$/')
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Model $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText('Lowercase; cannot change after creation.'),

                        Forms\Components\Select::make('type')
                            ->required()
                            ->options([
                                'string' => 'String',
                                'number' => 'Number',
                                'boolean' => 'Boolean',
                                // Stored as 'json' (see typedValue/castForStorage) but presented
                                // as "Object": a typed, nestable shape rather than raw JSON.
                                'json' => 'Object',
                            ])
                            ->default('string')
                            ->live(),

                        Forms\Components\Toggle::make('is_protected')
                            ->label('Protected')
                            ->inline(false)
                            ->helperText('Guard against casual edit/delete.'),

                        // Scalar states keep a simple default-value input. Object
                        // states describe their default through the shape builder
                        // below (per-field defaults), so no raw-JSON box here.
                        Forms\Components\Textarea::make('value')
                            ->label(fn (Get $get): string => 'Default value ('.Str::headline((string) $get('type')).')')
                            ->rows(2)
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('type') !== 'json')
                            ->helperText(fn (Get $get): ?string => match ($get('type')) {
                                'number' => 'A numeric value, e.g. 0.2 or 42.',
                                'boolean' => 'Use 1/0 or true/false.',
                                default => null,
                            })
                            ->columnSpanFull(),

                        // Object shape: the nested builder and a read-only JSON
                        // view of the same shape, on two tabs you can flip between.
                        Schemas\Components\Tabs::make('Shape')
                            ->visible(fn (Get $get): bool => $get('type') === 'json')
                            ->columnSpanFull()
                            ->tabs([
                                Schemas\Components\Tabs\Tab::make('Builder')
                                    ->schema([
                                        static::shapeRepeater()
                                            ->helperText('Define the object\'s fields, their types, and optional defaults. Nest Objects freely, or reuse another Object state.'),
                                    ]),
                                Schemas\Components\Tabs\Tab::make('JSON')
                                    ->schema([
                                        // The shape rendered as the actual object it
                                        // describes — keys with their default values,
                                        // nested — e.g. {"city":"Paris","geo":{"lat":"48.85"}}.
                                        Forms\Components\Placeholder::make('shape_json')
                                            ->label('Object (read-only)')
                                            ->content(fn (Get $get): HtmlString => new HtmlString(
                                                '<pre style="margin:0;padding:.75rem;background:#0f172a;color:#5eead4;'
                                                .'border-radius:.5rem;font-size:.75rem;line-height:1.5;overflow:auto;'
                                                .'max-height:24rem;white-space:pre;">'
                                                .e((string) json_encode(
                                                    app(StateShapeService::class)->composeDefault(
                                                        is_array($get('shape')) ? $get('shape') : []
                                                    ),
                                                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT
                                                ))
                                                .'</pre>'
                                            )),
                                    ]),
                            ]),

                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->maxLength(500)
                            ->nullable()
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }

    /** How deep the shape builder lets you nest Object-within-Object. */
    private const MAX_SHAPE_DEPTH = 3;

    /**
     * A recursive, typed shape builder (Filament Repeater). Each row is a field:
     * name + type (string/number/boolean/object/state), an optional scalar
     * default, a reused-state picker (type=state), and — for `object` — a nested
     * builder up to MAX_SHAPE_DEPTH. The root binds to the `shape` column; nested
     * rows to each field's `fields`.
     */
    protected static function shapeRepeater(int $depth = 0): Forms\Components\Repeater
    {
        $schema = [
            Forms\Components\TextInput::make('name')
                ->placeholder('fieldName')
                ->required()
                ->regex('/^[a-zA-Z_][a-zA-Z0-9_]*$/')
                ->live(onBlur: true) // keep the JSON tab in sync as fields are edited
                ->columnSpan(2),

            Forms\Components\Select::make('type')
                ->options([
                    'string' => 'String',
                    'number' => 'Number',
                    'boolean' => 'Boolean',
                    'object' => 'Object',
                    'state' => 'State (reuse)',
                ])
                ->default('string')
                ->required()
                ->live()
                ->columnSpan(2),

            Forms\Components\TextInput::make('default')
                ->label('Default')
                ->placeholder('optional')
                ->live(onBlur: true)
                ->visible(fn (Get $get): bool => in_array($get('type'), ['string', 'number', 'boolean'], true))
                ->columnSpan(2),

            Forms\Components\Select::make('ref')
                ->label('State')
                ->options(fn (): array => static::objectStateOptions())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('type') === 'state')
                ->columnSpan(2),
        ];

        if ($depth < self::MAX_SHAPE_DEPTH) {
            $schema[] = static::shapeRepeater($depth + 1)
                ->visible(fn (Get $get): bool => $get('type') === 'object')
                ->columnSpanFull();
        }

        return Forms\Components\Repeater::make($depth === 0 ? 'shape' : 'fields')
            ->label($depth === 0 ? 'Shape' : 'Nested fields')
            ->schema($schema)
            ->columns(6)
            ->addActionLabel('Add field')
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null)
                ? $state['name'].' : '.($state['type'] ?? 'string')
                : null)
            ->defaultItems(0);
    }

    /** Object states (type=json) offered to the "State (reuse)" field picker. */
    protected static function objectStateOptions(): array
    {
        /** @var class-string<Model> $model */
        $model = static::getModel();

        /** @var array<string,string> $options */
        $options = $model::query()->where('type', 'json')->orderBy('key')->pluck('key', 'key')->all();

        return $options;
    }

    /**
     * For an Object state, compose the `value` (default data) from the shape's
     * per-field defaults — this replaces the old hand-written JSON default box.
     * Called from the Create/Edit pages before save.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function composeValueFromShape(array $data): array
    {
        if (($data['type'] ?? null) === 'json') {
            $shape = is_array($data['shape'] ?? null) ? $data['shape'] : [];
            $data['value'] = json_encode(app(StateShapeService::class)->composeDefault($shape));
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'string' => 'info',
                        'number' => 'success',
                        'boolean' => 'warning',
                        'json' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('value')
                    ->limit(60)
                    ->tooltip(fn (Model $record): ?string => $record->value)
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_protected')
                    ->label('Protected')
                    ->boolean()
                    ->sortable(),

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
            'index' => Pages\ListVariables::route('/'),
            'create' => Pages\CreateVariable::route('/create'),
            'edit' => Pages\EditVariable::route('/{record}/edit'),
        ];
    }
}
