<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources;

use Andre\AiPageBuilder\Filament\Resources\PbApiTokenResource\Pages;
use Andre\AiPageBuilder\Models\PbApiToken;
use Andre\AiPageBuilder\Models\PbUser;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Manage long-lived Bearer tokens for the collections REST API. Creation mints
 * a token and surfaces the plaintext ONCE (it is stored hashed); tokens can be
 * tied to an app user so the API scopes data to that user's permissions, or
 * left ownerless for full access.
 */
class PbApiTokenResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-page-builder.models.api_token', PbApiToken::class);
    }

    public static function getModelLabel(): string
    {
        return 'API token';
    }

    public static function getNavigationLabel(): string
    {
        return 'API Tokens';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 6;
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

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Owner')
                    ->badge()
                    ->color('gray')
                    ->placeholder('full access')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->dateTime()
                    ->since()
                    ->placeholder('never')
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('never')
                    ->color(fn (Model $record): ?string => $record->expires_at?->isPast() ? 'danger' : null)
                    ->description(fn (Model $record): ?string => $record->expires_at?->isPast() ? 'expired' : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Actions\DeleteAction::make()
                    ->label('Revoke'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No API tokens yet')
            ->emptyStateDescription('Create a token to call the collections API over HTTP.');
    }

    /**
     * App user id => name, for the owner select. Empty when no app users exist
     * (an ownerless token = full access).
     *
     * @return array<int,string>
     */
    public static function userOptions(): array
    {
        /** @var class-string<PbUser> $userClass */
        $userClass = config('ai-page-builder.models.user', PbUser::class);

        return $userClass::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPbApiTokens::route('/'),
        ];
    }
}
