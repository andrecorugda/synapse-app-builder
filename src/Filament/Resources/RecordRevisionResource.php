<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\RecordRevisionResource\Pages;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Models\RecordRevision;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only viewer for the per-record change history (data revisions). Every
 * create/update/delete on a managed collection is snapshotted by RecordQuery;
 * this resource lets an admin browse those snapshots, inspect the before→after
 * diff, and (optionally) restore a prior state.
 */
class RecordRevisionResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.record_revision', RecordRevision::class);
    }

    public static function getModelLabel(): string
    {
        return 'record revision';
    }

    public static function getPluralModelLabel(): string
    {
        return 'record history';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.data', 'Data');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 5;
    }

    /** History is captured automatically; it is never authored by hand. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('collection')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('record_id')
                    ->label('Record')
                    ->searchable(),

                Tables\Columns\TextColumn::make('operation')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        RecordRevision::OP_CREATED => 'success',
                        RecordRevision::OP_UPDATED => 'info',
                        RecordRevision::OP_DELETED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('changed_by')
                    ->label('Changed by')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('collection')
                    ->options(fn (): array => static::collectionOptions()),

                Tables\Filters\SelectFilter::make('operation')
                    ->options([
                        RecordRevision::OP_CREATED => 'Created',
                        RecordRevision::OP_UPDATED => 'Updated',
                        RecordRevision::OP_DELETED => 'Deleted',
                    ]),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                static::restoreAction(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Revision')
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('collection')->badge(),
                        Infolists\Components\TextEntry::make('record_id')->label('Record'),
                        Infolists\Components\TextEntry::make('operation')->badge(),
                        Infolists\Components\TextEntry::make('created_at')->label('When')->dateTime(),
                        Infolists\Components\TextEntry::make('changed_by')->label('Changed by')->placeholder('—'),
                    ]),

                Schemas\Components\Section::make('Before')
                    ->visible(fn (RecordRevision $record): bool => ! empty($record->before))
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('before')
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('Value'),
                    ]),

                Schemas\Components\Section::make('After')
                    ->visible(fn (RecordRevision $record): bool => ! empty($record->after))
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('after')
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('Value'),
                    ]),
            ]);
    }

    /**
     * Re-apply a revision's prior state. For an `updated` revision the record
     * still exists → update it back to `before`. For a `deleted` revision the
     * row is gone → recreate it from `before`. A `created` revision has no
     * prior state, so the action is hidden for it. Everything runs through
     * RecordQuery so validation / casts / (a fresh) revision all apply.
     */
    protected static function restoreAction(): Actions\Action
    {
        return Actions\Action::make('restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Restore prior state')
            ->modalDescription('Re-apply the "before" snapshot of this record. This creates a new revision.')
            ->visible(fn (RecordRevision $record): bool => in_array(
                $record->operation,
                [RecordRevision::OP_UPDATED, RecordRevision::OP_DELETED],
                true,
            ) && ! empty($record->before) && static::resolveCollection($record->collection) !== null)
            ->action(function (RecordRevision $record): void {
                $model = static::resolveCollection($record->collection);
                if ($model === null) {
                    return;
                }

                $before = $record->before ?? [];
                $records = app(RecordQuery::class);

                // Strip system columns the write path manages itself.
                $payload = collect($before)
                    ->except(['id', 'created_at', 'updated_at', 'deleted_at'])
                    ->all();

                $exists = Record::for($model)->newQuery()
                    ->whereKey($record->record_id)->exists();

                if ($exists) {
                    $records->update($model, $record->record_id, $payload);
                } else {
                    $records->create($model, $payload);
                }
            });
    }

    /** Resolve a collection key to its (managed, writable) PbModel, or null. */
    protected static function resolveCollection(string $key): ?PbModel
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        $model = $modelClass::query()->where('key', $key)->first();

        if ($model === null || $model->isExternal() || $model->isReadOnly()) {
            return null;
        }

        return $model;
    }

    /**
     * Collection keys present in the history, for the filter dropdown.
     *
     * @return array<string,string>
     */
    protected static function collectionOptions(): array
    {
        /** @var class-string<RecordRevision> $revisionClass */
        $revisionClass = config('ai-page-builder.models.record_revision', RecordRevision::class);

        return $revisionClass::query()
            ->select('collection')
            ->distinct()
            ->orderBy('collection')
            ->pluck('collection', 'collection')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecordRevisions::route('/'),
            'view' => Pages\ViewRecordRevision::route('/{record}'),
        ];
    }
}
