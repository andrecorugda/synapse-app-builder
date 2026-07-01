<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbUser;
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
        $paginator = $this->records->list($this->resolve($model), $params, $this->expandAuthorizer());

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

        $record = $this->records->find($this->resolve($model), $id, $request->query(), $this->expandAuthorizer());

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
        if ($deny = $this->readOnlyDeny($model)) {
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
        if ($deny = $this->readOnlyDeny($model)) {
            return $deny;
        }

        $existing = $this->records->find($this->resolve($model), $id);
        if ($existing === null || ! $this->matchesRule($existing->toArray(), $model, 'update')) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        $allowed = $this->access->allowedFields($this->access->currentUser(), $model, 'update');
        $input = $allowed === null ? $request->all() : Arr::only($request->all(), $allowed);

        // Re-stamp rule-controlled fields so the caller can't reassign the row
        // (e.g. PATCH owner_id to give it away). The update rule wins over input;
        // the create rule's $CURRENT_USER fields are folded in too, so ownership
        // columns established at create stay pinned to the acting user on update.
        $ruleFields = array_merge(
            $this->access->rowRule($this->access->currentUser(), $model, 'create'),
            $this->access->rowRule($this->access->currentUser(), $model, 'update'),
        );
        $input = array_merge($input, $ruleFields);

        $record = $this->records->update($this->resolve($model), $id, $input);

        return response()->json(['data' => $record]);
    }

    public function destroy(string $model, int|string $id): JsonResponse
    {
        if ($deny = $this->gate('delete', $model)) {
            return $deny;
        }
        if ($deny = $this->readOnlyDeny($model)) {
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
     * Schema endpoint for a collection. Returns field definitions (key, label,
     * type, options), the computed display field, and a map of relation fields
     * to their related collection key + display field. Used by the front-end
     * data_table to render TYPE-DRIVEN cells without magic name guessing.
     *
     * Read-gated with the same permission check as the list endpoint, and it
     * honours the same field-level read restrictions: a role that may read only
     * a subset of columns sees only those fields here, and a relation whose
     * TARGET collection the user can't read is dropped from the relations map
     * (the expand is dropped server-side anyway). App-user relations (the
     * RELATION_TARGET sentinel) are not a `collection` resource and are omitted
     * from the relations map.
     */
    public function schema(string $model): JsonResponse
    {
        if ($deny = $this->gate('read', $model)) {
            return $deny;
        }

        $pbModel = $this->resolve($model);
        $user = $this->access->currentUser();
        $allowedFields = $this->access->allowedFields($user, $model, 'read');

        // Field-level projection: keep only readable fields (null = all).
        $fields = $allowedFields === null
            ? $pbModel->fields
            : $pbModel->fields->filter(
                static fn (PbField $f): bool => in_array($f->key, $allowedFields, true)
            )->values();

        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        $fieldList = $fields->map(static function (PbField $f): array {
            $opts = $f->options ?? [];

            return [
                'key' => $f->key,
                'label' => $f->label,
                'type' => $f->type,
                'options' => $f->type === FieldType::Select->value
                    ? array_values(array_map('strval', is_array($opts['choices'] ?? null) ? $opts['choices'] : []))
                    : null,
            ];
        })->values()->all();

        // Build the relations map: for each relation field, resolve its target
        // collection and expose that collection's display_field so the front-end
        // can render the related row's label rather than a raw id. Skip user
        // relations (not a collection resource) and any target the user may not
        // read.
        $relations = [];
        foreach ($fields as $f) {
            if ($f->type !== FieldType::Relation->value) {
                continue;
            }

            $relatedKey = (string) ($f->options['relation_model'] ?? '');
            if ($relatedKey === '' || $relatedKey === PbUser::RELATION_TARGET) {
                continue;
            }
            if (! $this->access->can($user, 'read', 'collection', $relatedKey)) {
                continue;
            }

            /** @var PbModel|null $relatedModel */
            $relatedModel = $modelClass::query()
                ->where('key', $relatedKey)
                ->with('fields')
                ->first();

            if ($relatedModel === null) {
                continue;
            }

            $relations[$f->key] = [
                'collection' => $relatedKey,
                'display_field' => $relatedModel->displayField(),
            ];
        }

        return response()->json([
            'fields' => $fieldList,
            'display_field' => $pbModel->displayField(),
            'relations' => $relations,
        ]);
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

    /** 403 when the collection is read-only (external or flagged), else null. */
    private function readOnlyDeny(string $model): ?JsonResponse
    {
        if ($this->resolve($model)->isReadOnly()) {
            return response()->json(['message' => 'This collection is read-only.'], 403);
        }

        return null;
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
     * Build the per-expand authorization callback handed to RecordQuery. For each
     * requested expand target it answers, from the acting user's permissions on
     * the RELATED collection: drop the expand entirely (no read access), or scope
     * the attached related rows by that collection's read row-rule + field list.
     *
     * User-target relations (the `PbUser::RELATION_TARGET` sentinel) are not a
     * `collection` resource — they're always allowed and rely on PbUser's $hidden
     * (password / 2FA stripped) for safe projection, so no field list is forced.
     *
     * @return callable(string,bool):(array{rule:array<string,mixed>,fields:array<int,string>|null}|false)
     */
    private function expandAuthorizer(): callable
    {
        return function (string $relatedKey, bool $isUser): array|false {
            if ($isUser) {
                return ['rule' => [], 'fields' => null];
            }

            $user = $this->access->currentUser();
            if (! $this->access->can($user, 'read', 'collection', $relatedKey)) {
                return false;
            }

            return [
                'rule' => $this->access->rowRule($user, $relatedKey, 'read'),
                'fields' => $this->access->allowedFields($user, $relatedKey, 'read'),
            ];
        };
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
