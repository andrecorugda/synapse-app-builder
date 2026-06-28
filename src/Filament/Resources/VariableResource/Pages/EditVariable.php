<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\VariableResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\VariableResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVariable extends EditRecord
{
    protected static string $resource = VariableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
