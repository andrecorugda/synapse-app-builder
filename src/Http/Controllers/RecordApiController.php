<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\Record;
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

        $user = $this->access->currentUser();
        $allowed = $this->access->allowedFields($user, $model, 'read');

        // The row rule goes to RecordQuery as written: it resolves field keys to
        // columns itself and fails closed (no rows) on a key it cannot resolve.
        $paginator = $this->records->list(
            $this->resolve($model),
            $request->query(),
            $this->expandAuthorizer(),
            $allowed,
            $this->access->rowRule($user, $model, 'read'),
        );

        $data = $paginator->toArray();
        if ($allowed !== null) {
            $keys = $this->projectionKeys($this->resolve($model), $allowed);
            $data['data'] = array_map(fn ($row) => $this->project((array) $row, $keys), $data['data']);
        }

        return response()->json($data);
    }

    public function show(Request $request, string $model, int|string $id): JsonResponse
    {
        if ($deny = $this->gate('read', $model)) {
            return $deny;
        }

        $pbModel = $this->resolve($model);
        $record = $this->records->find($pbModel, $id, $request->query(), $this->expandAuthorizer());

        if ($record === null || ! $this->matchesRule($record->toArray(), $pbModel, 'read')) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        $allowed = $this->access->allowedFields($this->access->currentUser(), $model, 'read');
        $arr = $record->toArray();

        return response()->json(['data' => $allowed === null ? $arr : $this->project($arr, $this->projectionKeys($pbModel, $allowed))]);
    }

    public function store(Request $request, string $model): JsonResponse
    {
        if ($deny = $this->gate('create', $model)) {
            return $deny;
        }
        if ($deny = $this->readOnlyDeny($model)) {
            return $deny;
        }

        $pbModel = $this->resolve($model);

        // Field-level write restriction: keep only the fields this role may set.
        $allowed = $this->access->allowedFields($this->access->currentUser(), $model, 'create');
        $input = $allowed === null ? $request->all() : Arr::only($request->all(), $allowed);

        // Stamp ownership: a "$CURRENT_USER" create rule forces those fields to
        // the acting user so new rows are owned by their creator (overrides input).
        $rule = $this->ruleFor($pbModel, 'create');
        if ($rule === null) {
            return $this->unresolvableRule();
        }
        $data = $this->stamp($pbModel, $input, $rule);

        $record = $this->records->create($pbModel, $data);

        return response()->json(['data' => $this->writeReply($pbModel, $record)], 201);
    }

    public function update(Request $request, string $model, int|string $id): JsonResponse
    {
        if ($deny = $this->gate('update', $model)) {
            return $deny;
        }
        if ($deny = $this->readOnlyDeny($model)) {
            return $deny;
        }

        $pbModel = $this->resolve($model);
        $existing = $this->records->find($pbModel, $id);
        if ($existing === null || ! $this->matchesRule($existing->toArray(), $pbModel, 'update')) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        $allowed = $this->access->allowedFields($this->access->currentUser(), $model, 'update');
        $input = $allowed === null ? $request->all() : Arr::only($request->all(), $allowed);

        // Re-stamp rule-controlled fields so the caller can't reassign the row
        // (e.g. PATCH owner_id to give it away). The update rule wins over input;
        // the create rule's $CURRENT_USER fields are folded in too, so ownership
        // columns established at create stay pinned to the acting user on update.
        $createRule = $this->ruleFor($pbModel, 'create');
        $updateRule = $this->ruleFor($pbModel, 'update');
        if ($createRule === null || $updateRule === null) {
            return $this->unresolvableRule();
        }
        $input = $this->stamp($pbModel, $input, array_merge($createRule, $updateRule));

        $record = $this->records->update($pbModel, $id, $input);
        if ($record === null) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        return response()->json(['data' => $this->writeReply($pbModel, $record)]);
    }

    public function destroy(string $model, int|string $id): JsonResponse
    {
        if ($deny = $this->gate('delete', $model)) {
            return $deny;
        }
        if ($deny = $this->readOnlyDeny($model)) {
            return $deny;
        }

        $pbModel = $this->resolve($model);
        $existing = $this->records->find($pbModel, $id);
        if ($existing === null || ! $this->matchesRule($existing->toArray(), $pbModel, 'delete')) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        $this->records->delete($pbModel, $id);

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
     * scoped, so a user only aggregates over rows they may see — and only over
     * columns they may read: `field`, `group_by`, `filter` and `search` cannot
     * name a column hidden from the role by a field-level restriction.
     */
    public function aggregate(Request $request, string $model): JsonResponse
    {
        if ($deny = $this->gate('read', $model)) {
            return $deny;
        }

        $user = $this->access->currentUser();

        return response()->json($this->records->aggregate(
            $this->resolve($model),
            $request->query(),
            $this->access->allowedFields($user, $model, 'read'),
            $this->access->rowRule($user, $model, 'read'),
        ));
    }

    /**
     * What a create/update reply may show: the saved row as the acting user
     * would READ it. Write permission is not read permission, so the reply is
     * projected to the role's read field list, and a row the role may not read
     * at all (no read permission, or outside the read row rule — e.g. an empty
     * PATCH on someone else's ticket) comes back as its id only.
     *
     * @return array<string,mixed>
     */
    private function writeReply(PbModel $model, Record $record): array
    {
        $row = $record->toArray();
        $idOnly = ['id' => $record->getKey()];

        $user = $this->access->currentUser();
        if (! $this->access->can($user, 'read', 'collection', $model->key)) {
            return $idOnly;
        }
        if (! $this->matchesRule($row, $model, 'read')) {
            return $idOnly;
        }

        $allowed = $this->access->allowedFields($user, $model->key, 'read');

        return $allowed === null ? $row : $this->project($row, $this->projectionKeys($model, $allowed));
    }

    /**
     * The row keys a role with a read field list may see: `id`, each readable
     * field key and the physical column behind it. Only a RELATION field adds a
     * different column (`owner` → `owner_id`); a plain field whose key happens
     * to be another field's key plus `_id` stays hidden unless itself readable.
     * Same key → column mapping as {@see RecordQuery::columnFor()}.
     *
     * @param  array<int,string>  $allowed
     * @return array<int,string>
     */
    private function projectionKeys(PbModel $model, array $allowed): array
    {
        $keys = ['id', ...$allowed];

        foreach ($model->fields as $field) {
            if (in_array($field->key, $allowed, true) || in_array($field->columnName(), $allowed, true)) {
                $keys[] = $field->key;
                $keys[] = $field->columnName();
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Keep only the given keys of a record row.
     *
     * @param  array<string,mixed>  $row
     * @param  array<int,string>  $keys  from {@see projectionKeys()}
     * @return array<string,mixed>
     */
    private function project(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
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
     * `collection` resource. They are always expandable, but projected to the
     * explicit `data.user_expand_fields` allow-list (default id + name) — an
     * expand must never hand out a user's email, role or two-factor state.
     *
     * @return callable(string,bool):(array{rule:array<string,mixed>,fields:array<int,string>|null}|false)
     */
    private function expandAuthorizer(): callable
    {
        return function (string $relatedKey, bool $isUser): array|false {
            if ($isUser) {
                return ['rule' => [], 'fields' => $this->userExpandFields()];
            }

            $user = $this->access->currentUser();
            if (! $this->access->can($user, 'read', 'collection', $relatedKey)) {
                return false;
            }

            $rule = $this->normalizedRule(
                $this->resolve($relatedKey),
                $this->access->rowRule($user, $relatedKey, 'read'),
            );

            // A rule that names an unknown column cannot be applied: drop the
            // expand rather than attach rows the rule was meant to hide.
            if ($rule === null) {
                return false;
            }

            return [
                'rule' => $rule,
                'fields' => $this->access->allowedFields($user, $relatedKey, 'read'),
            ];
        };
    }

    /**
     * The user columns an expand may return (id is always kept).
     *
     * @return array<int,string>
     */
    private function userExpandFields(): array
    {
        $fields = config('ai-page-builder.data.user_expand_fields', ['id', 'name']);

        return array_values(array_filter(
            is_array($fields) ? array_map('strval', $fields) : ['id', 'name'],
            static fn (string $f): bool => $f !== '',
        ));
    }

    /**
     * The acting user's row rule for an action, keyed by physical COLUMN. A rule
     * may name a field by its key (`owner`, what the Roles screen offers) or by
     * its column (`owner_id`); both resolve to the column. Null when a key names
     * neither — callers must then fail closed, never skip the rule.
     *
     * @return array<string,mixed>|null
     */
    private function ruleFor(PbModel $model, string $action): ?array
    {
        return $this->normalizedRule(
            $model,
            $this->access->rowRule($this->access->currentUser(), $model->key, $action),
        );
    }

    /**
     * @param  array<string,mixed>  $rule
     * @return array<string,mixed>|null
     */
    private function normalizedRule(PbModel $model, array $rule): ?array
    {
        $out = [];
        foreach ($rule as $key => $value) {
            $column = $this->records->columnFor($model, (string) $key);
            if ($column === null) {
                return null;
            }
            $out[$column] = $value;
        }

        return $out;
    }

    /**
     * Force rule-controlled columns onto a write payload. Input may address a
     * field by key OR column (and RecordQuery prefers the key), so both
     * spellings are removed before the rule's column value is set — otherwise
     * `{"owner": 5}` would win over a rule pinned on `owner_id`.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $rule  column => value
     * @return array<string,mixed>
     */
    private function stamp(PbModel $model, array $input, array $rule): array
    {
        if ($rule === []) {
            return $input;
        }

        foreach ($model->fields as $field) {
            if (array_key_exists($field->columnName(), $rule)) {
                unset($input[$field->key], $input[$field->columnName()]);
            }
        }

        return array_merge($input, $rule);
    }

    private function unresolvableRule(): JsonResponse
    {
        return response()->json(['message' => 'This action is not allowed.'], 403);
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function matchesRule(array $record, PbModel $model, string $action): bool
    {
        $rule = $this->ruleFor($model, $action);

        return $rule !== null && $this->access->recordMatchesRule($record, $rule);
    }

    private function resolve(string $key): PbModel
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        /** @var PbModel */
        return $modelClass::query()->where('key', $key)->firstOrFail();
    }
}
