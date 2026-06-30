<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities\Helpers;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;

/**
 * Data helpers — read and write collection records from inside a Function, through
 * the same RecordQuery layer the Record node and REST API use. Author-trusted
 * (like the Record node), so they run with full data access; the REST API remains
 * the permission-enforced edge. Wrap writing helpers in a Transaction node for
 * all-or-nothing behaviour.
 */
class DbHelpers implements HelperProvider
{
    public function __construct(private readonly RecordQuery $records) {}

    public function register(HelperRegistry $registry): void
    {
        $registry->register(
            new CapabilityDefinition(
                key: 'db_create',
                label: 'db.create',
                category: CapabilityCategory::Data,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Create a record in a collection. Returns the created row.',
                usage: "db_create('orders', {customer_id: 1, total: 99.5})",
                inputs: [
                    new CapabilityInput('collection', 'Collection key', 'collection', required: true),
                    new CapabilityInput('data', 'Field values', 'json', required: true),
                ],
            ),
            fn (string $collection, array $data): array => $this->records->create($this->model($collection), $data)->toArray(),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'db_update',
                label: 'db.update',
                category: CapabilityCategory::Data,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Update a record by id. Returns the updated row (or null if missing).',
                usage: "db_update('orders', 42, {status: 'paid'})",
                inputs: [
                    new CapabilityInput('collection', 'Collection key', 'collection', required: true),
                    new CapabilityInput('id', 'Record id', 'expression', required: true),
                    new CapabilityInput('data', 'Field values', 'json', required: true),
                ],
            ),
            fn (string $collection, int|string $id, array $data): ?array => $this->records->update($this->model($collection), $id, $data)?->toArray(),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'db_delete',
                label: 'db.delete',
                category: CapabilityCategory::Data,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Delete a record by id. Returns true on success.',
                usage: "db_delete('cart_items', 7)",
                inputs: [
                    new CapabilityInput('collection', 'Collection key', 'collection', required: true),
                    new CapabilityInput('id', 'Record id', 'expression', required: true),
                ],
            ),
            fn (string $collection, int|string $id): bool => $this->records->delete($this->model($collection), $id),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'db_find',
                label: 'db.find',
                category: CapabilityCategory::Data,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Fetch a single record by id. Returns the row or null.',
                usage: "db_find('products', input.product_id)",
                inputs: [
                    new CapabilityInput('collection', 'Collection key', 'collection', required: true),
                    new CapabilityInput('id', 'Record id', 'expression', required: true),
                ],
            ),
            fn (string $collection, int|string $id): ?array => $this->records->find($this->model($collection), $id)?->toArray(),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'db_list',
                label: 'db.list',
                category: CapabilityCategory::Data,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'List records with optional Directus-style params (filter, sort, search, per_page). Returns an array of rows.',
                usage: "db_list('orders', {filter: {status: {eq: 'open'}}, sort: '-created_at'})",
                inputs: [
                    new CapabilityInput('collection', 'Collection key', 'collection', required: true),
                    new CapabilityInput('params', 'Query params', 'json'),
                ],
            ),
            fn (string $collection, array $params = []): array => array_map(
                static fn ($r): array => $r->toArray(),
                $this->records->list($this->model($collection), $params)->items(),
            ),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'db_aggregate',
                label: 'db.aggregate',
                category: CapabilityCategory::Data,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'Aggregate a column (sum/count/avg/min/max), optionally grouped. Returns {metric, total, rows}.',
                usage: "db_aggregate('order_items', {metric: 'sum', field: 'subtotal', filter: {order_id: {eq: 42}}})",
                inputs: [
                    new CapabilityInput('collection', 'Collection key', 'collection', required: true),
                    new CapabilityInput('params', 'Aggregate params', 'json', required: true),
                ],
            ),
            fn (string $collection, array $params): array => $this->records->aggregate($this->model($collection), $params),
        );
    }

    private function model(string $key): PbModel
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        /** @var PbModel */
        return $modelClass::query()->where('key', $key)->firstOrFail();
    }
}
