<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\WatcherResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\WatcherResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWatcher extends CreateRecord
{
    protected static string $resource = WatcherResource::class;

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return WatcherResource::normalizeConfig($data);
    }
}
