<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\CredentialResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\CredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCredentials extends ListRecords
{
    protected static string $resource = CredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
