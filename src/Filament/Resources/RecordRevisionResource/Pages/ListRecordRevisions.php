<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\RecordRevisionResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\RecordRevisionResource;
use Filament\Resources\Pages\ListRecords;

class ListRecordRevisions extends ListRecords
{
    protected static string $resource = RecordRevisionResource::class;
}
