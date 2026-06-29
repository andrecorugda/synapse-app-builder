<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\FlowRunResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\FlowRunResource;
use Filament\Resources\Pages\ListRecords;

class ListFlowRuns extends ListRecords
{
    protected static string $resource = FlowRunResource::class;
}
