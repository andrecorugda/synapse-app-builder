<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\CredentialResource\Pages;
use Andre\AiPageBuilder\Models\Credential;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CredentialResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.credential', Credential::class);
    }

    public static function getModelLabel(): string
    {
        return 'credential';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Credentials';
    }

    public static function getNavigationLabel(): string
    {
        return 'Credentials';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 4;
    }

    /** The key is the route key (defined in Credential::getRouteKeyName). */
    public static function getRecordRouteKeyName(): string
    {
        return 'key';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Credential')
                    ->description('Stored encrypted. Referenced by key from an HTTP-request flow node so flows call external APIs without inlining secrets.')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(120),

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
                                'bearer' => 'Bearer token',
                                'api_key' => 'API key header',
                                'basic' => 'Basic auth',
                            ])
                            ->default('bearer')
                            ->live(),

                        Forms\Components\TextInput::make('meta.header_name')
                            ->label('Header name')
                            ->placeholder('X-API-Key')
                            ->maxLength(120)
                            ->visible(fn (Get $get): bool => $get('type') === 'api_key')
                            ->helperText('Defaults to X-API-Key when blank.'),

                        Forms\Components\TextInput::make('meta.username')
                            ->label('Username')
                            ->maxLength(120)
                            ->autocomplete('off')
                            ->visible(fn (Get $get): bool => $get('type') === 'basic'),

                        Forms\Components\TextInput::make('secret')
                            ->label(fn (Get $get): string => $get('type') === 'basic' ? 'Password' : 'Secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (?Model $record): bool => $record === null)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->placeholder(fn (?Model $record): string => $record !== null ? '•••••• (leave blank to keep)' : '')
                            ->helperText('Leave blank to keep the current secret.')
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
                        'bearer' => 'info',
                        'api_key' => 'success',
                        'basic' => 'warning',
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
            'index' => Pages\ListCredentials::route('/'),
            'create' => Pages\CreateCredential::route('/create'),
            'edit' => Pages\EditCredential::route('/{record}/edit'),
        ];
    }
}
