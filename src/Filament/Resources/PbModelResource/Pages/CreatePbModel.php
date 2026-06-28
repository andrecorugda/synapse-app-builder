<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbModelResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbModelResource;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Filament\Resources\Pages\CreateRecord;

class CreatePbModel extends CreateRecord
{
    protected static string $resource = PbModelResource::class;

    /**
     * Resolve the physical table name from the collection key before insert —
     * the model has no creating event of its own.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['table_name'] = PbModel::physicalTableName((string) $data['key']);

        return $data;
    }

    /**
     * Build the physical table once the record and its fields have persisted.
     */
    protected function afterCreate(): void
    {
        app(SchemaSynchronizer::class)->sync($this->getRecord());
    }
}
