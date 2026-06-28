<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbRoleResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbRoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPbRole extends EditRecord
{
    protected static string $resource = PbRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
