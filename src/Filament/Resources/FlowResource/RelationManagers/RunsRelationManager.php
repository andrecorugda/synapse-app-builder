<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\FlowResource\RelationManagers;

use Andre\AiPageBuilder\Models\FlowRun;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only run history for a flow, shown as a "Runs" tab on the flow edit page
 * (runs belong to a flow, so they live here instead of a top-level menu). Each
 * row opens a detail modal with the step timeline + result + input.
 */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Runs';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    public function form(Schema $schema): Schema
    {
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

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'error' ? 'danger' : 'success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('trigger_type')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->numeric()
                    ->suffix(' ms')
                    ->sortable(),

                Tables\Columns\TextColumn::make('error')
                    ->limit(40)
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['ok' => 'OK', 'error' => 'Error']),
            ])
            ->headerActions([])
            ->recordActions([
                Actions\Action::make('details')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->modalHeading('Run details')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (FlowRun $record) => view('ai-page-builder::filament.flow-run-detail', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
