<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbRoleResource\RelationManagers;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbModel;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
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
                        ->default('collection')
                        ->live(),

                    Forms\Components\Select::make('resource_key')
                        ->options(fn (Get $get): array => static::resourceKeyOptions($get('resource_type')))
                        ->searchable()
                        ->required()
                        ->default('*')
                        ->live()
                        ->helperText('Collection / page to grant, or * for all'),

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

                Forms\Components\Select::make('fields')
                    ->label('Fields (column-level)')
                    ->multiple()
                    ->searchable()
                    ->options(fn (Get $get): array => static::fieldOptions($get('resource_type'), $get('resource_key')))
                    ->live()
                    ->nullable()
                    ->helperText('Optional: restrict this action to these field keys only. Leave empty for all fields.')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Options for the resource_key select, scoped to the chosen resource type:
     * collection names keyed by collection key, page titles keyed by slug, each
     * prefixed with an "All resources" (*) catch-all.
     *
     * @return array<string,string>
     */
    protected static function resourceKeyOptions(?string $resourceType): array
    {
        if ($resourceType === 'collection') {
            /** @var class-string<PbModel> $modelClass */
            $modelClass = config('ai-page-builder.models.model', PbModel::class);

            return $modelClass::query()
                ->orderBy('name')
                ->pluck('name', 'key')
                ->prepend('All resources', '*')
                ->all();
        }

        if ($resourceType === 'page') {
            /** @var class-string<Page> $pageClass */
            $pageClass = config('ai-page-builder.models.page', Page::class);

            return $pageClass::query()
                ->pages()
                ->orderBy('title')
                ->pluck('title', 'slug')
                ->prepend('All resources', '*')
                ->all();
        }

        return ['*' => 'All resources'];
    }

    /**
     * Field options (label keyed by field key) for the chosen collection. Empty
     * unless a concrete collection (non-* resource_key) is selected — pages and
     * the "all" wildcard have no column-level field list.
     *
     * @return array<string,string>
     */
    protected static function fieldOptions(?string $resourceType, ?string $resourceKey): array
    {
        if ($resourceType !== 'collection' || $resourceKey === null || $resourceKey === '' || $resourceKey === '*') {
            return [];
        }

        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        $model = $modelClass::query()->where('key', $resourceKey)->first();

        if ($model === null) {
            return [];
        }

        return $model->fields()->pluck('label', 'key')->all();
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
