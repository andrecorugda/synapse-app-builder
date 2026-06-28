<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\VariableResource\Pages;
use Andre\AiPageBuilder\Models\Variable;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
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
        return 'variable';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
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
                Forms\Components\TextInput::make('key')
                    ->required()
                    ->maxLength(120)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Model $record): bool => $record !== null)
                    ->dehydrated()
                    ->helperText('Lowercase letter followed by lowercase letters, numbers, or underscores. Cannot be changed after creation.'),

                Forms\Components\Select::make('type')
                    ->required()
                    ->options([
                        'string' => 'String',
                        'number' => 'Number',
                        'boolean' => 'Boolean',
                        'json' => 'JSON',
                    ])
                    ->default('string')
                    ->live(),

                Forms\Components\Textarea::make('value')
                    ->label(fn (Get $get): string => 'Value ('.Str::headline((string) $get('type')).')')
                    ->rows(fn (Get $get): int => $get('type') === 'json' ? 6 : 2)
                    ->nullable()
                    ->helperText(fn (Get $get): ?string => match ($get('type')) {
                        'number' => 'A numeric value, e.g. 0.2 or 42.',
                        'boolean' => 'Use 1/0 or true/false.',
                        'json' => 'Valid JSON, e.g. {"a":1} or [1,2,3].',
                        default => null,
                    }),

                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->nullable(),

                Forms\Components\Toggle::make('is_protected')
                    ->label('Protected')
                    ->helperText('Mark variables that should not be casually edited or deleted.'),
            ])
            ->columns(1);
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
