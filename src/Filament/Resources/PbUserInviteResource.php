<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Auth\InviteService;
use Andre\AiPageBuilder\Filament\Resources\PbUserInviteResource\Pages;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUserInvite;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PbUserInviteResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.user_invite', PbUserInvite::class);
    }

    public static function getModelLabel(): string
    {
        return 'invite';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Invites';
    }

    public static function getNavigationLabel(): string
    {
        return 'Invites';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 7;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('role.name')
                    ->label('Role')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->state(fn (PbUserInvite $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'accepted' => 'success',
                        'expired' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Actions\Action::make('send')
                    ->label('Send invite')
                    ->icon('heroicon-m-paper-airplane')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required(),

                        Forms\Components\Select::make('role_id')
                            ->label('Role')
                            ->options(
                                fn (): array => config('ai-page-builder.models.role', PbRole::class)::query()
                                    ->pluck('name', 'id')
                                    ->all()
                            )
                            ->nullable(),
                    ])
                    ->action(function (array $data): void {
                        app(InviteService::class)->createAndSend(
                            (string) $data['email'],
                            $data['role_id'] ? (int) $data['role_id'] : null,
                            auth()->id(),
                        );

                        Notification::make()
                            ->success()
                            ->title('Invitation sent')
                            ->send();
                    }),
            ])
            ->recordActions([
                Actions\Action::make('resend')
                    ->label('Resend')
                    ->icon('heroicon-m-arrow-path')
                    ->color('gray')
                    ->visible(fn (PbUserInvite $record): bool => ! $record->isAccepted())
                    ->requiresConfirmation()
                    ->action(function (PbUserInvite $record): void {
                        app(InviteService::class)->resend($record);

                        Notification::make()
                            ->success()
                            ->title('Invitation resent')
                            ->send();
                    }),

                Actions\DeleteAction::make()
                    ->label('Revoke'),
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
            'index' => Pages\ListPbUserInvites::route('/'),
        ];
    }
}
