<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\AccessControl;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;

/**
 * Directus-style auto REST API over user-defined models. Every model is exposed
 * at `{data.api_prefix}/{model}` and resolved by its collection key. The same
 * RecordQuery service backs the Flow Record node and Functions.
 *
 * End-user permissions are enforced here (the HTTP edge), NOT inside RecordQuery
 * — flows/functions/admin go through RecordQuery directly and are trusted. The
 * model is opt-in: a collection with no permission rows is fully open; once a
 * role defines permissions for it, every action is gated and row-level rules
 * narrow which rows the acting user may read/write.
 */
class RecordApiController extends Controller
{
    public function __construct(
        private readonly RecordQuery $records,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request, string $model): JsonResponse
    {
        if ($deny = $this->gate('read', $model)) {
            return $deny;
        }

        $params = $this->applyRowRule($request->query(), $model, 'read');
        $paginator = $this->records->list($this->resolve($model), $params);

        $allowed = $this->access->allowedFields($this->access->currentUser(), $model, 'read');
        $data = $paginator->toArray();
        if ($allowed !== null) {
            $data['data'] = array_map(fn ($row) => $this->project((array) $row, $allowed), $data['data']);
        }

        return response()->json($data);
    }

    public function show(Request $request, string $model, int|string $id): JsonResponse
    {
        if ($deny = $this->gate('read', $model)) {
            return $deny;
        }

        $record = $this->records->find($this->resolve($model), $id, $request->query());

        if ($record === null || ! $this->matchesRule($record->toArray(), $model, 'read')) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        $allowed = $this->access->allowedFields($this->access->currentUser(), $model, 'read');
        $arr = $record->toArray();

        return response()->json(['data' => $allowed === null ? $arr : $this->project($arr, $allowed)]);
    }

    public function store(Request $request, string $model): JsonResponse
    {
        if ($deny = $this->gate('create', $model)) {
            return $deny;
        }

        // Field-level write restriction: keep only the fields this role may set.
        $allowed = $this->access->allowedFields($this->access->currentUser(), $model, 'create');
        $input = $allowed === null ? $request->all() : Arr::only($request->all(), $allowed);

        // Stamp ownership: a "$CURRENT_USER" create rule forces those fields to
        // the acting user so new rows are owned by their creator (overrides input).
        $data = array_merge($input, $this->access->rowRule($this->access->currentUser(), $model, 'create'));

        $record = $this->records->create($this->resolve($model), $data);

        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, string $model, int|string $id): JsonResponse
    {
        if ($deny = $this->gate('update', $model)) {
            return $deny;
        }

        $existing = $this->records->find($this->resolve($model), $id);
        if ($existing === null || ! $this->matchesRule($existing->toArray(), $model, 'update')) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        $allowed = $this->access->allowedFields($this->access->currentUser(), $model, 'update');
        $input = $allowed === null ? $request->all() : Arr::only($request->all(), $allowed);

        $record = $this->records->update($this->resolve($model), $id, $input);

        return response()->json(['data' => $record]);
    }

    public function destroy(string $model, int|string $id): JsonResponse
    {
        if ($deny = $this->gate('delete', $model)) {
            return $deny;
        }

        $existing = $this->records->find($this->resolve($model), $id);
        if ($existing === null || ! $this->matchesRule($existing->toArray(), $model, 'delete')) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        $this->records->delete($this->resolve($model), $id);

        return response()->json(null, 204);
    }

    /**
     * Server-side aggregation for charts / KPI cards. Read-gated and row-rule
     * scoped, so a user only aggregates over rows they may see.
     */
    public function aggregate(Request $request, string $model): JsonResponse
    {
        if ($deny = $this->gate('read', $model)) {
            return $deny;
        }

        $params = $this->applyRowRule($request->query(), $model, 'read');

        return response()->json($this->records->aggregate($this->resolve($model), $params));
    }

    /**
     * Keep only the field-level-allowed keys of a record row (id is always kept
     * so rows stay addressable).
     *
     * @param  array<string,mixed>  $row
     * @param  array<int,string>  $allowed
     * @return array<string,mixed>
     */
    private function project(array $row, array $allowed): array
    {
        return array_intersect_key($row, array_flip([...['id'], ...$allowed]));
    }

    /** 403 JsonResponse when the current user can't perform $action, else null. */
    private function gate(string $action, string $model): ?JsonResponse
    {
        if ($this->access->can($this->access->currentUser(), $action, 'collection', $model)) {
            return null;
        }

        return response()->json(['message' => 'This action is not allowed.'], 403);
    }

    /**
     * Merge the acting user's row rule into the list filter (field => eq value).
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function applyRowRule(array $params, string $model, string $action): array
    {
        $rule = $this->access->rowRule($this->access->currentUser(), $model, $action);
        if ($rule === []) {
            return $params;
        }

        /** @var array<string,mixed> $filter */
        $filter = is_array($params['filter'] ?? null) ? $params['filter'] : [];
        foreach ($rule as $field => $value) {
            $filter[$field] = $value; // scalar → equality in RecordQuery
        }
        $params['filter'] = $filter;

        return $params;
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function matchesRule(array $record, string $model, string $action): bool
    {
        return $this->access->recordMatchesRule(
            $record,
            $this->access->rowRule($this->access->currentUser(), $model, $action),
        );
    }

    private function resolve(string $key): PbModel
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        /** @var PbModel */
        return $modelClass::query()->where('key', $key)->firstOrFail();
    }
}
