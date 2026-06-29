<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbUserInviteResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbUserInviteResource;
use Filament\Resources\Pages\ListRecords;

class ListPbUserInvites extends ListRecords
{
    protected static string $resource = PbUserInviteResource::class;
}
