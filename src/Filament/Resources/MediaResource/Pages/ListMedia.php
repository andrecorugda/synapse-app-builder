<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\MediaResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\MediaResource;
use Filament\Resources\Pages\ListRecords;

class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;
}
