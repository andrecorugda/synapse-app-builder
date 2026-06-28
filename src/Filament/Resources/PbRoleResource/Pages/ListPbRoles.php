<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbRoleResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbRoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPbRoles extends ListRecords
{
    protected static string $resource = PbRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
