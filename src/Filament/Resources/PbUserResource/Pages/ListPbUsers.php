<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbUserResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbUserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPbUsers extends ListRecords
{
    protected static string $resource = PbUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
