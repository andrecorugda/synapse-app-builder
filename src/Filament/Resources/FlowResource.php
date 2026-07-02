<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Forms\Components\FlowCanvasField;
use Andre\AiPageBuilder\Filament\Resources\FlowResource\Pages;
use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Models\Flow;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FlowResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.flow', Flow::class);
    }

    public static function getModelLabel(): string
    {
        return 'flow';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.automation', 'Automation');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 1;
    }

    /** The slug is the route key (defined in Flow::getRouteKeyName). */
    public static function getRecordRouteKeyName(): string
    {
        return 'slug';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Flow')
                    ->compact()
                    ->schema([
                        // Row 1 — slug · name · active · public
                        Schemas\Components\Grid::make(4)->schema([
                            Forms\Components\TextInput::make('slug')
                                ->required()
                                ->maxLength(200)
                                ->regex('/^[a-z0-9\-_]+$/')
                                ->unique(ignoreRecord: true)
                                ->helperText('Lowercase, numbers, dashes — the route key.'),

                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->maxLength(200),

                            Forms\Components\Select::make('is_active')
                                ->label('Active')
                                ->options(['1' => 'Active', '0' => 'Inactive'])
                                ->default('0')
                                ->selectablePlaceholder(false)
                                ->formatStateUsing(fn ($state): string => $state ? '1' : '0'),

                            Forms\Components\Select::make('is_public')
                                ->label('Public')
                                ->options(['1' => 'Public', '0' => 'Private'])
                                ->default('0')
                                ->selectablePlaceholder(false)
                                ->formatStateUsing(fn ($state): string => $state ? '1' : '0')
                                ->helperText('Public allows unauthenticated trigger.'),
                        ]),

                        // Row 2 — rate limit · trigger label. A flow is a reusable
                        // graph: WHAT fires it lives elsewhere (Public/Private for
                        // pages & the API endpoint, Watchers for collection/state
                        // events, Schedules for cron) — trigger_type is advisory.
                        Schemas\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('rate_limit_per_minute')
                                ->label('Rate limit / min')
                                ->numeric()
                                ->nullable()
                                ->minValue(0)
                                ->placeholder('Global default'),

                            Forms\Components\Select::make('trigger_type')
                                ->label('Trigger label')
                                ->options([
                                    'manual' => 'Manual',
                                    'component' => 'Component',
                                    'form' => 'Form',
                                    'cron' => 'Cron',
                                    'api' => 'API',
                                    'collection' => 'Collection event',
                                ])
                                ->default('manual')
                                ->helperText('A label — any active flow can be triggered from pages or the API '
                                    .'(governed by Public/Private). Bind collection/state events under Watchers '
                                    .'and cron runs under Schedules. "Cron" also marks the flow for the legacy '
                                    .'run-cron-flows command.'),
                        ]),
                    ]),

                FlowCanvasField::make('definition')
                    ->label('Flow definition')
                    ->columnSpanFull(),
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
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('trigger_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'manual' => 'gray',
                        'component' => 'info',
                        'form' => 'success',
                        'cron' => 'warning',
                        'api' => 'primary',
                        'collection' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('trigger_type')
                    ->options([
                        'manual' => 'Manual',
                        'component' => 'Component',
                        'form' => 'Form',
                        'cron' => 'Cron',
                        'api' => 'API',
                        'collection' => 'Collection event',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                Actions\Action::make('run')
                    ->label('Run now')
                    ->icon('heroicon-m-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Run this flow now')
                    ->modalDescription('Runs the flow immediately with empty input and records a run (see Flow Runs).')
                    ->action(function (Flow $record): void {
                        try {
                            $ctx = app(FlowManager::class)->run($record, []);
                            Notification::make()
                                ->status($ctx->failed ? 'warning' : 'success')
                                ->title($ctx->failed ? 'Flow ran with an error' : 'Flow ran')
                                ->body($ctx->failed ? ($ctx->error ?? 'See Flow Runs for details.') : (count($ctx->actions).' action(s) returned.'))
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Run failed')->body($e->getMessage())->send();
                        }
                    }),
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
            FlowResource\RelationManagers\RunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlows::route('/'),
            'create' => Pages\CreateFlow::route('/create'),
            'edit' => Pages\EditFlow::route('/{record}/edit'),
        ];
    }
}
