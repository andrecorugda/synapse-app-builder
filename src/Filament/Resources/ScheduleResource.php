<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\ScheduleResource\Pages;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Schedule;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ScheduleResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.schedule', Schedule::class);
    }

    public static function getModelLabel(): string
    {
        return 'schedule';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 4;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('Schedule')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(160),

                        Forms\Components\TextInput::make('cron_expression')
                            ->label('Cron expression')
                            ->required()
                            ->maxLength(120)
                            ->default('*/5 * * * *')
                            ->placeholder('*/5 * * * *')
                            ->helperText('Standard 5-field cron (minute hour day month weekday). '
                                .'Presets: "*/5 * * * *" every 5 min · "0 * * * *" hourly · '
                                .'"0 9 * * *" daily 09:00 · "0 9 * * 1" Mondays 09:00.'),

                        Forms\Components\Select::make('target_type')
                            ->label('Target type')
                            ->required()
                            ->options([
                                'flow' => 'Flow',
                                'function' => 'Function',
                            ])
                            ->default('flow')
                            ->live()
                            // Clear a now-mismatched target when the type flips.
                            ->afterStateUpdated(fn (Forms\Components\Select $component) => $component
                                ->getContainer()
                                ->getComponent('target_key')
                                ?->state(null)),

                        Forms\Components\Select::make('target_key')
                            ->key('target_key')
                            ->label('Target')
                            ->required()
                            ->searchable()
                            ->options(fn (Get $get): array => self::targetOptions((string) $get('target_type')))
                            ->helperText('The flow or function (by slug) to run when due.'),

                        Forms\Components\TextInput::make('timezone')
                            ->maxLength(64)
                            ->placeholder('UTC')
                            ->helperText('Optional. Evaluate the cron in this timezone (e.g. Europe/Paris). '
                                .'Defaults to the app timezone.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->inline(false)
                            ->default(true),

                        Forms\Components\KeyValue::make('args')
                            ->label('Arguments')
                            ->keyLabel('Key')
                            ->valueLabel('Value')
                            ->nullable()
                            ->helperText('Passed as input/args to the flow or function.')
                            ->columnSpanFull(),
                    ]),
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

                Tables\Columns\TextColumn::make('cron_expression')
                    ->label('Cron')
                    ->badge()
                    ->color('gray')
                    ->copyable(),

                Tables\Columns\TextColumn::make('target')
                    ->label('Target')
                    ->state(fn (Model $record): string => $record->target_type.':'.$record->target_key)
                    ->color('gray'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),

                Tables\Columns\TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->dateTime()
                    ->placeholder('never')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_status')
                    ->label('Last status')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'ok' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchedules::route('/'),
            'create' => Pages\CreateSchedule::route('/create'),
            'edit' => Pages\EditSchedule::route('/{record}/edit'),
        ];
    }

    /**
     * Slug => "Name (slug)" options for the chosen target type, pulled from the
     * configured Flow / Function models (mirrors how other resources build
     * model-backed option lists).
     *
     * @return array<string,string>
     */
    private static function targetOptions(string $targetType): array
    {
        if ($targetType === 'function') {
            /** @var class-string<FlowFunction> $model */
            $model = config('ai-page-builder.models.flow_function', FlowFunction::class);
        } else {
            /** @var class-string<Flow> $model */
            $model = config('ai-page-builder.models.flow', Flow::class);
        }

        return $model::query()
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->mapWithKeys(fn (Model $row): array => [
                $row->slug => sprintf('%s (%s)', $row->name, $row->slug),
            ])
            ->all();
    }
}
