<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PageResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PageResource;
use Andre\AiPageBuilder\Support\PageDataMapper;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    protected static string $resource = PageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PageDataMapper::split($data);
    }
}
