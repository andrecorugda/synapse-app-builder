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
     * Apply collection-level changes (e.g. timestamps / soft-deletes toggles)
     * to the physical table.
     */
    protected function afterSave(): void
    {
        app(SchemaSynchronizer::class)->sync($this->getRecord());
    }
}
