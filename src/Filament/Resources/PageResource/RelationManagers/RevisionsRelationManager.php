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
 * Read-only version history for a page. Each save/publish creates a version;
 * per row you can Preview a version and Apply it (make it the live content).
 * Applying snapshots the current state first, so it's always reversible — you
 * can apply a different version again at any time.
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
                        'restore' => 'Applied',
                        'before_restore' => 'Before apply',
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
                Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->modalHeading('Version preview')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (PageRevision $record) => view('ai-page-builder::filament.revision-preview', ['revision' => $record])),

                Actions\Action::make('apply')
                    ->label('Apply this version')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Apply this version')
                    ->modalDescription('This version becomes the page\'s live content. The current version is snapshotted first, so you can apply a different one again at any time.')
                    ->modalSubmitActionLabel('Apply version')
                    ->action(function (PageRevision $record): void {
                        /** @var Page $page */
                        $page = $this->getOwnerRecord();
                        $page->restoreRevision($record);

                        Notification::make()
                            ->success()
                            ->title('Version applied')
                            ->body('The page now uses the version from '.$record->created_at?->diffForHumans().'.')
                            ->send();
                    }),
            ]);
    }
}
