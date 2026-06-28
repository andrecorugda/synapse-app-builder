<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PageResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
