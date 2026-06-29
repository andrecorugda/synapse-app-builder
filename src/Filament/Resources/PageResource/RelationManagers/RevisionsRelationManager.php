<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PageResource\RelationManagers;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PageRevision;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only version history for a page, with a per-row Restore action that
 * rolls the page back to the selected snapshot (snapshotting current state
 * first, so the restore is reversible).
 */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Versions';

    public function form(Schema $schema): Schema
    {
        // Revisions are immutable snapshots — never edited through a form.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'publish' => 'Published',
                        'restore' => 'Restored',
                        'before_restore' => 'Before restore',
                        default => 'Saved',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'publish' => 'success',
                        'restore' => 'warning',
                        'before_restore' => 'gray',
                        default => 'info',
                    }),

                Tables\Columns\TextColumn::make('title'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('created_by')
                    ->label('Author')
                    ->placeholder('—')
                    ->color('gray'),
            ])
            ->recordActions([
                Actions\Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Restore this version')
                    ->modalDescription('The page will be rolled back to this snapshot. The current state is saved first, so you can undo the restore.')
                    ->action(function (PageRevision $record): void {
                        /** @var Page $page */
                        $page = $this->getOwnerRecord();
                        $page->restoreRevision($record);

                        Notification::make()
                            ->success()
                            ->title('Page restored')
                            ->body('Restored to the version from '.$record->created_at?->diffForHumans().'.')
                            ->send();
                    }),
            ]);
    }
}
