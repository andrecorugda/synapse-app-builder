<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\ScheduleResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\ScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSchedule extends EditRecord
{
    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
