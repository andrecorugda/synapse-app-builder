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
     * Build the (initially field-less) table immediately so the edit page's
     * Fields relation manager has a table to keep in sync.
     */
    protected function afterCreate(): void
    {
        app(SchemaSynchronizer::class)->sync($this->getRecord());
    }

    /** Land on the edit page so fields can be added straight away. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
