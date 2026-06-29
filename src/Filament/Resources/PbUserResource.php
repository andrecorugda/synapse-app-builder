<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Auth\TwoFactorService;
use Andre\AiPageBuilder\Filament\Resources\PbUserResource\Pages;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PbUserResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.user', PbUser::class);
    }

    public static function getModelLabel(): string
    {
        return 'app user';
    }

    public static function getNavigationLabel(): string
    {
        return 'App Users';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_groups.access', 'Access');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 5;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make('App User')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(160),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(190)
                            ->unique(ignoreRecord: true),

                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255)
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave blank to keep current' : null),

                        Forms\Components\Select::make('role_id')
                            ->label('Role')
                            ->options(fn (): array => static::roleOptions())
                            ->searchable()
                            ->native(false)
                            ->nullable(),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                PbUser::STATUS_ACTIVE => 'Active',
                                PbUser::STATUS_PENDING => 'Pending',
                                PbUser::STATUS_SUSPENDED => 'Suspended',
                            ])
                            ->default(PbUser::STATUS_ACTIVE)
                            ->native(false)
                            ->required(),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->inline(false)
                            ->default(true),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('role.name')
                    ->label('Role')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PbUser::STATUS_ACTIVE => 'success',
                        PbUser::STATUS_PENDING => 'warning',
                        PbUser::STATUS_SUSPENDED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->badge()
                    ->state(fn (PbUser $record): string => $record->hasTwoFactorEnabled() ? 'on' : 'off')
                    ->color(fn (string $state): string => $state === 'on' ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role_id')
                    ->label('Role')
                    ->options(fn (): array => static::roleOptions()),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        PbUser::STATUS_ACTIVE => 'Active',
                        PbUser::STATUS_PENDING => 'Pending',
                        PbUser::STATUS_SUSPENDED => 'Suspended',
                    ]),
            ])
            ->recordActions([
                Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PbUser $record): bool => $record->getAttribute('status') === PbUser::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (PbUser $record): void {
                        $record->update(['status' => PbUser::STATUS_ACTIVE]);

                        Notification::make()
                            ->title('User approved')
                            ->success()
                            ->send();
                    }),

                Actions\Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (PbUser $record): bool => $record->getAttribute('status') === PbUser::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (PbUser $record): void {
                        $record->update(['status' => PbUser::STATUS_SUSPENDED]);

                        Notification::make()
                            ->title('User suspended')
                            ->success()
                            ->send();
                    }),

                Actions\Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (PbUser $record): bool => $record->getAttribute('status') === PbUser::STATUS_SUSPENDED)
                    ->requiresConfirmation()
                    ->action(function (PbUser $record): void {
                        $record->update(['status' => PbUser::STATUS_ACTIVE]);

                        Notification::make()
                            ->title('User reactivated')
                            ->success()
                            ->send();
                    }),

                Actions\Action::make('resetTwoFactor')
                    ->label('Reset 2FA')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('warning')
                    ->visible(fn (PbUser $record): bool => $record->hasTwoFactorEnabled())
                    ->requiresConfirmation()
                    ->action(function (PbUser $record): void {
                        app(TwoFactorService::class)->disable($record);

                        Notification::make()
                            ->title('Two-factor reset')
                            ->body('The user must set it up again.')
                            ->success()
                            ->send();
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

    /**
     * Role id => name, for the role select / filter.
     *
     * @return array<int,string>
     */
    public static function roleOptions(): array
    {
        /** @var class-string<PbRole> $roleClass */
        $roleClass = config('ai-page-builder.models.role', PbRole::class);

        return $roleClass::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPbUsers::route('/'),
            'create' => Pages\CreatePbUser::route('/create'),
            'edit' => Pages\EditPbUser::route('/{record}/edit'),
        ];
    }
}
