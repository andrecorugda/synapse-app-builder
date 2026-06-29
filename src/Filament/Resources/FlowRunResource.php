<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\FlowRunResource\Pages;
use Andre\AiPageBuilder\Models\FlowRun;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FlowRunResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.flow_run', FlowRun::class);
    }

    public static function getModelLabel(): string
    {
        return 'flow run';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Flow Runs';
    }

    public static function getNavigationLabel(): string
    {
        return 'Flow Runs';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        // Sit just after Flows (which is navigation_sort + 1).
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 2;
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('flow_slug_snapshot')
                    ->label('Flow')
                    ->searchable()
                    ->sortable()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'ok' => 'success',
                        'error' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('trigger_type')
                    ->badge()
                    ->sortable()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->numeric()
                    ->suffix(' ms')
                    ->sortable(),

                Tables\Columns\TextColumn::make('error')
                    ->limit(40)
                    ->tooltip(fn (Model $record): ?string => $record->error)
                    ->color('danger')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'ok' => 'OK',
                        'error' => 'Error',
                    ]),
                Tables\Filters\SelectFilter::make('trigger_type')
                    ->options([
                        'manual' => 'Manual',
                        'component' => 'Component',
                        'form' => 'Form',
                        'cron' => 'Cron',
                        'api' => 'API',
                        'collection' => 'Collection event',
                    ]),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Run')
                    ->compact()
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('flow_slug_snapshot')
                            ->label('Flow')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'ok' => 'success',
                                'error' => 'danger',
                                default => 'gray',
                            }),

                        Infolists\Components\TextEntry::make('trigger_type')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('duration_ms')
                            ->label('Duration')
                            ->numeric()
                            ->suffix(' ms')
                            ->placeholder('—'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->dateTime()
                            ->columnSpan(2),

                        Infolists\Components\TextEntry::make('error')
                            ->color('danger')
                            ->columnSpan(2)
                            ->placeholder('—'),
                    ]),

                Schemas\Components\Section::make('Steps')
                    ->compact()
                    ->schema([
                        Infolists\Components\ViewEntry::make('steps')
                            ->hiddenLabel()
                            ->view('ai-page-builder::filament.flow-run-steps'),
                    ]),

                Schemas\Components\Section::make('Result')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('result.vars')
                            ->label('Variables')
                            ->keyLabel('Variable')
                            ->valueLabel('Value')
                            ->placeholder('No variables'),

                        Infolists\Components\CodeEntry::make('result.actions')
                            ->label('Actions')
                            ->grammar('json')
                            ->state(fn (Model $record): array => (array) ($record->result['actions'] ?? [])),
                    ]),

                Schemas\Components\Section::make('Input')
                    ->compact()
                    ->collapsed()
                    ->schema([
                        Infolists\Components\CodeEntry::make('input')
                            ->hiddenLabel()
                            ->grammar('json')
                            ->state(fn (Model $record): array => (array) ($record->input ?? [])),
                    ]),
            ])
            ->columns(1);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlowRuns::route('/'),
            'view' => Pages\ViewFlowRun::route('/{record}'),
        ];
    }
}
