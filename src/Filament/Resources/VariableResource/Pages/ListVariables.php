<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\VariableResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\VariableResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVariables extends ListRecords
{
    protected static string $resource = VariableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
