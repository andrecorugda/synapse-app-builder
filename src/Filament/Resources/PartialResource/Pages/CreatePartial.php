<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PartialResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PartialResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePartial extends CreateRecord
{
    protected static string $resource = PartialResource::class;
}
