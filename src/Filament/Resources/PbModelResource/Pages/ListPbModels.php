<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbModelResource\Pages;

use Andre\AiPageBuilder\Filament\Resources\PbModelResource;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPbModels extends ListRecords
{
    protected static string $resource = PbModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Create a collection in a side drawer (mirrors the edit flow).
            Actions\CreateAction::make()
                ->slideOver()
                ->mutateFormDataUsing(function (array $data): array {
                    // Resolve the physical table name from the key before insert.
                    $data['table_name'] = PbModel::physicalTableName((string) $data['key']);

                    return $data;
                })
                ->after(fn (PbModel $record): mixed => app(SchemaSynchronizer::class)->sync($record)),
        ];
    }
}
