<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Illuminate\Support\Facades\Log;

/**
 * Reads or writes records of a user-defined data model from within a flow,
 * through the same RecordQuery service the REST API uses.
 *
 * Node config shape:
 *   {
 *     "model":     "<collection key>",
 *     "operation": "list|get|create|update|delete",
 *     "id":        "{{ input.id }}",   // get|update|delete
 *     "filter":    { "status": { "eq": "open" } },  // list
 *     "data":      { "name": "{{ input.name }}" },  // create|update
 *     "output":    "records"            // ctx var to store the result (default "records")
 *   }
 */
class RecordNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    public function __construct(private readonly RecordQuery $records) {}

    public function type(): string
    {
        return 'record';
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Record',
            category: CapabilityCategory::Data,
            description: 'Reads or writes records of a data collection from inside a flow, through the same query layer the REST API uses. The result is stored in a context variable. The collection is author-fixed and never taken from caller input.',
            usage: 'model "tickets", operation "create", data {title: "{{ input.title }}"}, output "ticket" → creates a record and exposes {{ vars.ticket }}. For list, "filter" applies; for get/update/delete, "id" selects the row.',
            icon: 'circle-stack',
            inputs: [
                new CapabilityInput('model', 'Collection', 'collection', required: true, help: 'Key of the data collection to operate on. Fixed by the author (not interpolated) for security.'),
                new CapabilityInput('operation', 'Operation', 'select', default: 'list', options: [
                    'list' => 'list',
                    'get' => 'get',
                    'create' => 'create',
                    'update' => 'update',
                    'delete' => 'delete',
                ]),
                new CapabilityInput('id', 'Record ID', 'expression', help: 'Record id for get / update / delete (interpolated).'),
                new CapabilityInput('filter', 'Filter', 'json', help: 'Filter object for list, e.g. {"status": {"eq": "open"}} (interpolated).'),
                new CapabilityInput('data', 'Data', 'json', help: 'Field values for create / update (interpolated).'),
                new CapabilityInput('output', 'Output variable', 'string', default: 'records', help: 'Context variable to receive the result (default "records").'),
            ],
            outputHandles: ['next'],
        );
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,string>
     */
    public function run(array $node, FlowContext $context): array
    {
        $raw = (array) ($node['config'] ?? []);

        // Structural fields are author-fixed and NEVER interpolated from caller
        // input — otherwise a public/unauthenticated flow's caller could pass
        // `{{ input.model }}` and redirect the operation to ANY collection (IDOR).
        // Only the value-bearing fields (filter/data/id/…) below are interpolated.
        $key = (string) ($raw['model'] ?? '');
        $operation = (string) ($raw['operation'] ?? 'list');
        $output = (string) ($raw['output'] ?? 'records');

        /** @var array<string,mixed> $config */
        $config = $context->interpolateDeep($raw);

        // The value-bearing fields (create/update `data`, the `id` selector, list
        // `filter`) are resolved expression-aware: a `{{ token }}` interpolates as
        // before, a bare EL expression (`vars.order['id']`, `'ORD-' ~ util_uuid()`)
        // evaluates with its type preserved, and a plain literal (0, "open") is
        // untouched. Structural fields (model/operation/output) stay author-fixed.
        if (array_key_exists('data', $raw)) {
            $config['data'] = $context->resolveDynamic($raw['data']);
        }
        if (array_key_exists('id', $raw)) {
            $config['id'] = $context->resolveDynamic($raw['id']);
        }
        if (array_key_exists('filter', $raw)) {
            $config['filter'] = $context->resolveDynamic($raw['filter']);
        }

        $result = null;

        if ($key !== '') {
            // A write that fails must SURFACE: it propagates so the flow's error
            // handling runs and — crucially — a wrapping Transaction node rolls
            // back. Silently swallowing a failed write would let a transaction
            // "succeed" on half-written data. Reads stay graceful (null on error).
            $isWrite = in_array($operation, ['create', 'update', 'delete'], true);
            try {
                $model = $this->resolve($key);
                $result = $this->execute($model, $operation, $config);
            } catch (\Throwable $e) {
                Log::warning('[ai-page-builder] record node failed: '.$e->getMessage());
                if ($isWrite) {
                    throw $e;
                }
            }
        }

        $context->set($output, $result);

        return (array) ($node['next'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function execute(PbModel $model, string $operation, array $config): mixed
    {
        return match ($operation) {
            'list' => $this->records->list($model, [
                'filter' => (array) ($config['filter'] ?? []),
                'sort' => (string) ($config['sort'] ?? ''),
                'search' => (string) ($config['search'] ?? ''),
                'per_page' => $config['per_page'] ?? null,
                'page' => $config['page'] ?? 1,
            ])->items(),
            'get' => $this->records->find($model, $config['id'] ?? 0)?->toArray(),
            'create' => $this->records->create($model, (array) ($config['data'] ?? []))->toArray(),
            'update' => $this->records->update($model, $config['id'] ?? 0, (array) ($config['data'] ?? []))?->toArray(),
            'delete' => $this->records->delete($model, $config['id'] ?? 0),
            default => null,
        };
    }

    private function resolve(string $key): PbModel
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        /** @var PbModel */
        return $modelClass::query()->where('key', $key)->firstOrFail();
    }
}
