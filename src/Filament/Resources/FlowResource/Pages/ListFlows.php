<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\FlowResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\FlowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFlows extends ListRecords
{
    protected static string $resource = FlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
