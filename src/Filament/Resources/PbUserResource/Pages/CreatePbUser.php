<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbUserResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePbUser extends CreateRecord
{
    protected static string $resource = PbUserResource::class;
}
