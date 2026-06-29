<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\PbRoleResource\Pages;
use Andre\AiPageBuilder\Filament\Resources\PbRoleResource\RelationManagers\PermissionsRelationManager;
use Andre\AiPageBuilder\Models\PbRole;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PbRoleResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.role', PbRole::class);
    }

    public static function getModelLabel(): string
    {
        return 'role';
    }

    public static function getNavigationLabel(): string
    {
        return 'Roles';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.access', 'Access');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 6;
    }

    /** The slug is the route key (defined in PbRole::getRouteKeyName). */
    public static function getRecordRouteKeyName(): string
    {
        return 'slug';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Role')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(160)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('slug', Str::slug((string) $state))),

                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(160)
                            ->unique(ignoreRecord: true)
                            ->helperText('Stable identifier used in permission checks.'),

                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->maxLength(500)
                            ->nullable()
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_admin')
                            ->label('Admin')
                            ->inline(false)
                            ->helperText('Admin roles bypass all permission checks — full access.'),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['users', 'permissions']))
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Users')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->sortable()
                    ->alignCenter(),
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

    public static function getRelations(): array
    {
        return [
            PermissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPbRoles::route('/'),
            'create' => Pages\CreatePbRole::route('/create'),
            'edit' => Pages\EditPbRole::route('/{record}/edit'),
        ];
    }
}
