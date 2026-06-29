<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\ScheduleResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\ScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSchedule extends CreateRecord
{
    protected static string $resource = ScheduleResource::class;
}
