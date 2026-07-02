<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\VariableResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\VariableResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVariable extends CreateRecord
{
    protected static string $resource = VariableResource::class;

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return VariableResource::composeValueFromShape($data);
    }
}
