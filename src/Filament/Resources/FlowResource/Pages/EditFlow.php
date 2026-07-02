<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\FlowResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\FlowResource;
use Andre\AiPageBuilder\Flow\FlowManager;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFlow extends EditRecord
{
    protected static string $resource = FlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Run the flow without leaving the editor. Persists the current
            // canvas first (so it runs exactly what's on screen), then executes
            // with empty input and records a run (see the Runs tab).
            Actions\Action::make('run')
                ->label('Run now')
                ->icon('heroicon-m-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Run this flow now')
                ->modalDescription('Saves your current changes, then runs the flow immediately with empty input and records a run (see Flow Runs).')
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    try {
                        $ctx = app(FlowManager::class)->run($this->getRecord()->refresh(), []);
                        Notification::make()
                            ->status($ctx->failed ? 'warning' : 'success')
                            ->title($ctx->failed ? 'Flow ran with an error' : 'Flow ran')
                            ->body($ctx->failed ? ($ctx->error ?? 'See Flow Runs for details.') : (count($ctx->actions).' action(s) returned.'))
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Run failed')->body($e->getMessage())->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
