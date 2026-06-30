<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
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
class RecordNode implements FlowNodeHandler
{
    public function __construct(private readonly RecordQuery $records) {}

    public function type(): string
    {
        return 'record';
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

        $result = null;

        if ($key !== '') {
            try {
                $model = $this->resolve($key);
                $result = $this->execute($model, $operation, $config);
            } catch (\Throwable $e) {
                Log::warning('[ai-page-builder] record node failed: '.$e->getMessage());
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
