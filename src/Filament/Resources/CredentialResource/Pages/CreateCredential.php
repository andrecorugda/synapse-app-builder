<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\CredentialResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\CredentialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCredential extends CreateRecord
{
    protected static string $resource = CredentialResource::class;
}
