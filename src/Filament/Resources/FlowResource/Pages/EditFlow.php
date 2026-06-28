<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\FlowResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\FlowResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFlow extends EditRecord
{
    protected static string $resource = FlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return FlowResource::denormalizeTriggerConfig($data);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return FlowResource::normalizeTriggerConfig($data);
    }
}
