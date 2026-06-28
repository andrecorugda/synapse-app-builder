<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\FunctionResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\FunctionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFunctions extends ListRecords
{
    protected static string $resource = FunctionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
