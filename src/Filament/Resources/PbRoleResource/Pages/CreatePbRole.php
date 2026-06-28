<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbRoleResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbRoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePbRole extends CreateRecord
{
    protected static string $resource = PbRoleResource::class;
}
