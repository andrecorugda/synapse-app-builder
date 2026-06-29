<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PartialResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PartialResource;
use Andre\AiPageBuilder\Support\PartialDataMapper;
use Filament\Resources\Pages\CreateRecord;

class CreatePartial extends CreateRecord
{
    protected static string $resource = PartialResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PartialDataMapper::split($data);
    }
}
