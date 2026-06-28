<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\FlowResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\FlowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlow extends CreateRecord
{
    protected static string $resource = FlowResource::class;

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return FlowResource::normalizeTriggerConfig($data);
    }
}
