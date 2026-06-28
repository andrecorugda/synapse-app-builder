<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Directus-style auto REST API over user-defined models. Every model is exposed
 * at `{data.api_prefix}/{model}` and resolved by its collection key. The same
 * RecordQuery service backs the Flow Record node and Functions, so behaviour is
 * identical across all three. Validation failures surface as 422 automatically.
 */
class RecordApiController extends Controller
{
    public function __construct(private readonly RecordQuery $records) {}

    public function index(Request $request, string $model): JsonResponse
    {
        $paginator = $this->records->list($this->resolve($model), $request->query());

        return response()->json($paginator->toArray());
    }

    public function show(Request $request, string $model, int|string $id): JsonResponse
    {
        $record = $this->records->find($this->resolve($model), $id, $request->query());

        if ($record === null) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        return response()->json(['data' => $record]);
    }

    public function store(Request $request, string $model): JsonResponse
    {
        $record = $this->records->create($this->resolve($model), $request->all());

        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, string $model, int|string $id): JsonResponse
    {
        $record = $this->records->update($this->resolve($model), $id, $request->all());

        if ($record === null) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        return response()->json(['data' => $record]);
    }

    public function destroy(string $model, int|string $id): JsonResponse
    {
        $deleted = $this->records->delete($this->resolve($model), $id);

        if (! $deleted) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        return response()->json(null, 204);
    }

    private function resolve(string $key): PbModel
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        /** @var PbModel */
        return $modelClass::query()->where('key', $key)->firstOrFail();
    }
}
