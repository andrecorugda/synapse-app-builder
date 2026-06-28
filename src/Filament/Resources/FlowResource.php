<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Forms\Components\FlowCanvasField;
use Andre\AiPageBuilder\Filament\Resources\FlowResource\Pages;
use Andre\AiPageBuilder\Models\Flow;
use Filament\Actions;
use Filament\Forms;
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
        return config('ai-page-builder.filament.navigation_group', 'Content');
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
                Schemas\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(200)
                        ->regex('/^[a-z0-9\-_]+$/')
                        ->unique(ignoreRecord: true)
                        ->helperText('Lowercase letters, numbers, dashes — used as the route key.')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(200)
                        ->columnSpan(2),

                    Forms\Components\Select::make('trigger_type')
                        ->required()
                        ->options([
                            'manual' => 'Manual',
                            'component' => 'Component',
                            'form' => 'Form',
                            'cron' => 'Cron',
                            'api' => 'API',
                        ])
                        ->default('manual')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('rate_limit_per_minute')
                        ->numeric()
                        ->nullable()
                        ->minValue(0)
                        ->placeholder('Inherit global default')
                        ->helperText('Leave blank to use the global default rate limit.')
                        ->columnSpan(1),

                    Schemas\Components\Grid::make(2)->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(false),

                        Forms\Components\Toggle::make('is_public')
                            ->label('Public')
                            ->default(false)
                            ->helperText('Allow unauthenticated trigger via the public API endpoint.'),
                    ])->columnSpan(1),
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
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
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
            'index' => Pages\ListFlows::route('/'),
            'create' => Pages\CreateFlow::route('/create'),
            'edit' => Pages\EditFlow::route('/{record}/edit'),
        ];
    }
}
