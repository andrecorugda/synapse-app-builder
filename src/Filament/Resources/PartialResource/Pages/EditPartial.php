<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PartialResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PartialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPartial extends EditRecord
{
    protected static string $resource = PartialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
