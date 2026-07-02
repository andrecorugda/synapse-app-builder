<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\WatcherResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\WatcherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWatchers extends ListRecords
{
    protected static string $resource = WatcherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
