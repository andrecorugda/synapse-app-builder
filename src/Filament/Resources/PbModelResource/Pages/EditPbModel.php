<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbModelResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbModelResource;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPbModel extends EditRecord
{
    protected static string $resource = PbModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(fn (PbModel $record): mixed => app(SchemaSynchronizer::class)->dropTable($record)),
        ];
    }

    /**
     * Bring the physical table in line with the saved field set (added columns,
     * timestamps/soft-delete toggles).
     */
    protected function afterSave(): void
    {
        app(SchemaSynchronizer::class)->sync($this->getRecord());
    }
}
