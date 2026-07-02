<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\WatcherResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\WatcherResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWatcher extends EditRecord
{
    protected static string $resource = WatcherResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
