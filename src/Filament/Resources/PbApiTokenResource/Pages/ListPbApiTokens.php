<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbApiTokenResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbApiTokenResource;
use Andre\AiPageBuilder\Models\PbApiToken;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPbApiTokens extends ListRecords
{
    protected static string $resource = PbApiTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create token')
                ->icon('heroicon-m-plus')
                ->modalSubmitActionLabel('Create')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Token name')
                        ->required()
                        ->maxLength(160)
                        ->placeholder('e.g. n8n production'),

                    Forms\Components\Select::make('pb_user_id')
                        ->label('Acts as (app user)')
                        ->options(fn (): array => PbApiTokenResource::userOptions())
                        ->searchable()
                        ->native(false)
                        ->nullable()
                        ->helperText('Scopes the API to this user\'s permissions and row-level rules. Leave empty for full access.'),

                    Forms\Components\Select::make('expires_in_days')
                        ->label('Expiration')
                        ->options([
                            '' => 'Never',
                            '7' => '7 days',
                            '30' => '30 days',
                            '90' => '90 days',
                            '365' => '1 year',
                        ])
                        ->default('')
                        ->selectablePlaceholder(false)
                        ->native(false)
                        ->helperText('Expired tokens are rejected automatically.'),
                ])
                ->action(fn (array $data) => $this->createToken($data)),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    protected function createToken(array $data): void
    {
        $expiresInDays = $data['expires_in_days'] ?? null;
        $expiresAt = ($expiresInDays !== null && $expiresInDays !== '')
            ? now()->addDays((int) $expiresInDays)
            : null;

        $pbUserId = ($data['pb_user_id'] ?? null) !== null ? (int) $data['pb_user_id'] : null;

        /** @var class-string<PbApiToken> $tokenClass */
        $tokenClass = config('ai-page-builder.models.api_token', PbApiToken::class);

        $result = $tokenClass::generate(
            (string) $data['name'],
            $pbUserId,
            null,
            $expiresAt,
        );

        $plain = $result['plain_text'];

        $expiryNote = $expiresAt !== null
            ? "\n\nExpires {$expiresAt->toDayDateTimeString()}."
            : '';

        Notification::make()
            ->success()
            ->title('Token created — shown once')
            ->body('Copy it now; it will not be shown again:'."\n\n".$plain.$expiryNote)
            ->persistent()
            ->actions([
                // Render as a link (url '#') so Filament does NOT wire it to a
                // server-side mountAction — the copy is purely client-side, via a
                // delegated listener keyed off the data-pb-token-copy attribute.
                NotificationAction::make('copy')
                    ->label('Copy to clipboard')
                    ->icon('heroicon-m-clipboard-document')
                    ->button()
                    ->url('#')
                    ->extraAttributes([
                        'data-pb-token-copy' => $plain,
                    ]),
            ])
            ->send();
    }
}
