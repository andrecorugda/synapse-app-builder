<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbRoleResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manages a role's permission grants as a compact table on the role edit page.
 * Each row is one action on a resource, with an optional row-level rule for
 * collections (Directus-style).
 */
class PermissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'permissions';

    protected static ?string $title = 'Permissions';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('resource_type')
                        ->options([
                            'collection' => 'Collection',
                            'page' => 'Page',
                        ])
                        ->required()
                        ->default('collection'),

                    Forms\Components\TextInput::make('resource_key')
                        ->required()
                        ->default('*')
                        ->maxLength(160)
                        ->helperText('Collection key / page slug, or * for all'),

                    Forms\Components\Select::make('action')
                        ->options([
                            '*' => 'All',
                            'create' => 'Create',
                            'read' => 'Read',
                            'update' => 'Update',
                            'delete' => 'Delete',
                            'view' => 'View',
                        ])
                        ->required()
                        ->default('read'),
                ]),

                Forms\Components\KeyValue::make('rule')
                    ->keyLabel('Field')
                    ->valueLabel('Equals (use $CURRENT_USER for the logged-in user id)')
                    ->nullable()
                    ->helperText('Optional row-level rule for collections.')
                    ->columnSpanFull(),

                Forms\Components\TagsInput::make('fields')
                    ->label('Fields (column-level)')
                    ->placeholder('Add a field key')
                    ->nullable()
                    ->helperText('Optional: restrict this action to these field keys only. Leave empty for all fields.')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('resource_type')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('resource_key'),

                Tables\Columns\TextColumn::make('action')
                    ->badge(),

                Tables\Columns\TextColumn::make('rule')
                    ->label('Rule')
                    ->formatStateUsing(fn ($state): ?string => filled($state) ? json_encode($state) : null)
                    ->color('gray'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add permission'),
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
}
