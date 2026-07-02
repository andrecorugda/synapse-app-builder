<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\WatcherResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\WatcherResource;
use Andre\AiPageBuilder\Flow\WatcherDispatcher;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditWatcher extends EditRecord
{
    protected static string $resource = WatcherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Fire the target once with a representative payload (conditions
            // bypassed) to check the wiring — records a run (see the Runs tab).
            Actions\Action::make('testFire')
                ->label('Test fire')
                ->icon('heroicon-m-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Test fire this watcher')
                ->modalDescription('Runs the target once with a sample payload (ignoring conditions) and records a run. Does not change any data unless the target flow writes.')
                ->action(function (): void {
                    try {
                        app(WatcherDispatcher::class)->testFire($this->getRecord());
                        $record = $this->getRecord()->refresh();
                        Notification::make()
                            ->status($record->last_status === 'failed' ? 'warning' : 'success')
                            ->title($record->last_status === 'failed' ? 'Target ran with an error' : 'Target ran')
                            ->body($record->last_error ?? 'See the Runs tab for details.')
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Test fire failed')->body($e->getMessage())->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return WatcherResource::denormalizeConfig($data);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return WatcherResource::normalizeConfig($data);
    }
}
