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
     * The source of an external collection (or switching a collection to
     * external) may only change with synapse.manage-data-sources — checked
     * here on the server, not only by disabling the inputs.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var PbModel $record */
        $record = $this->getRecord();
        $wantsExternal = ($data['source_type'] ?? $record->source_type) === PbModel::SOURCE_EXTERNAL;

        if (($record->isExternal() || $wantsExternal) && ! PbModelResource::canManageDataSources()) {
            foreach (['source_type', 'source_connection', 'table_name', 'is_read_only'] as $key) {
                if (array_key_exists($key, $data) && $data[$key] != $record->getAttribute($key)) {
                    abort(403);
                }
            }
        }

        if (! $wantsExternal) {
            // A managed collection's table always follows its key.
            $data['table_name'] = $record->isExternal()
                ? PbModel::physicalTableName($record->key)
                : $record->table_name;
            $data['source_connection'] = null;
        }

        return $data;
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
