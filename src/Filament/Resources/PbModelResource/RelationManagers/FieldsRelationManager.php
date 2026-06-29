<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbModelResource\RelationManagers;

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manages a collection's fields as a compact table on the collection edit page.
 * Each field is one read-only row; clicking it opens a modal to edit just that
 * field. Every add/edit/delete/reorder re-syncs the real database table.
 */
class FieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'fields';

    protected static ?string $title = 'Fields';

    protected static ?string $recordTitleAttribute = 'label';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                    ->label('Belongs to')
                    ->options(fn (): array => PbModelResource::relationModelOptions())
                    ->searchable()
                    ->helperText('App users or another collection this field references (stored as {key}_id). Name the field for its role — e.g. author, approver, assignee.')
                    ->visible(fn (Get $get): bool => $get('type') === FieldType::Relation->value),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('key')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => (FieldType::tryFrom($state) ?? FieldType::String)->label()),

                Tables\Columns\IconColumn::make('options.required')
                    ->label('Required')
                    ->boolean(),

                Tables\Columns\IconColumn::make('options.unique')
                    ->label('Unique')
                    ->boolean(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add field')
                    ->after(fn (): mixed => $this->syncSchema()),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->after(fn (): mixed => $this->syncSchema()),
                Actions\DeleteAction::make()
                    ->after(fn (): mixed => $this->syncSchema()),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->after(fn (): mixed => $this->syncSchema()),
                ]),
            ]);
    }

    /**
     * Bring the owning collection's real table in line with its fields after a
     * field is added, edited, deleted, or reordered.
     */
    private function syncSchema(): void
    {
        /** @var PbModel $owner */
        $owner = $this->getOwnerRecord();

        app(SchemaSynchronizer::class)->sync($owner);
    }
}
