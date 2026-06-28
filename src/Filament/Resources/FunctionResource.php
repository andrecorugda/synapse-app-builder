<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Forms\Components\CodeField;
use Andre\AiPageBuilder\Filament\Resources\FunctionResource\Pages;
use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Models\FlowFunction;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FunctionResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.flow_function', FlowFunction::class);
    }

    public static function getModelLabel(): string
    {
        return 'function';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 2;
    }

    /** The slug is the route key (defined in FlowFunction::getRouteKeyName). */
    public static function getRecordRouteKeyName(): string
    {
        return 'slug';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(120)
                    ->regex('/^[a-z][a-z0-9_-]*$/')
                    ->unique(ignoreRecord: true)
                    ->helperText('Lowercase letter followed by lowercase letters, numbers, underscores, or dashes.'),

                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(160),

                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->nullable(),

                Forms\Components\Select::make('runtime')
                    ->required()
                    ->options(function (): array {
                        $options = [
                            'expression' => 'Expression (safe, sandboxed)',
                            'callable' => 'Registered callable (developer-registered)',
                        ];

                        if ((bool) config('ai-page-builder.flow.allow_php_functions', false)) {
                            $options['php'] = 'PHP script (runs arbitrary PHP)';
                        }

                        return $options;
                    })
                    ->default('expression')
                    ->live(),

                CodeField::make('body')
                    ->label('Expression')
                    ->language('javascript')
                    ->height(120)
                    ->helperText('Symfony ExpressionLanguage over input/vars/args, e.g. args["price"] * 1.2. Read app State with state(\'key\') or states[\'key\'].')
                    ->visible(fn (Get $get): bool => $get('runtime') === 'expression'),

                Forms\Components\Select::make('body')
                    ->label('Registered callable')
                    ->options(function (): array {
                        /** @var FunctionRegistry $registry */
                        $registry = app(FunctionRegistry::class);
                        $keys = $registry->keys();

                        return array_combine($keys, $keys);
                    })
                    ->helperText('Choose a callable registered via FunctionRegistry::register() at boot.')
                    ->visible(fn (Get $get): bool => $get('runtime') === 'callable'),

                CodeField::make('body')
                    ->label('PHP script')
                    ->language('php')
                    ->height(360)
                    ->helperText('Runs as PHP. $args, $input and $vars are available; end with `return <value>;`. Read State via $states[\'key\']; write with app(\Andre\AiPageBuilder\Services\Data\VariableStore::class)->set(\'key\', $value). ⚠ Executes arbitrary code on your server — only for trusted authors (your own app).')
                    ->visible(fn (Get $get): bool => $get('runtime') === 'php'),
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

                Tables\Columns\TextColumn::make('runtime')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'expression' => 'info',
                        'callable' => 'success',
                        'php' => 'danger',
                        default => 'gray',
                    }),

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
            'index' => Pages\ListFunctions::route('/'),
            'create' => Pages\CreateFunction::route('/create'),
            'edit' => Pages\EditFunction::route('/{record}/edit'),
        ];
    }
}
