<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services\Data;

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Models\RecordRevision;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single source of truth for reading and writing records of a user-defined
 * model. Used by the REST API, the Flow Record node, and Functions — so every
 * consumer shares the same filtering, validation, and column whitelisting.
 *
 * Query params (Directus-style):
 *   filter[field][op]=value   ops: eq neq gt gte lt lte like in nin null nnull between
 *   sort=field,-other         leading '-' = descending
 *   fields=a,b,c              column projection (always includes id)
 *   search=term               LIKE across string/text fields
 *   page / per_page           pagination (per_page clamped to data.max_per_page)
 */
class RecordQuery
{
    private const OPERATORS = [
        'eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'like' => 'like',
    ];

    /**
     * @param  array<string,mixed>  $params
     */
    /**
     * @param  array<string,mixed>  $params
     * @param  (callable(string,bool):(array{rule?:array<string,mixed>,fields?:array<int,string>|null}|false))|null  $authorizeExpand
     */
    public function list(PbModel $model, array $params = [], ?callable $authorizeExpand = null): LengthAwarePaginator
    {
        $query = Record::for($model)->newQuery();

        $this->applyFilters($model, $query, (array) ($params['filter'] ?? []));
        $this->applySearch($model, $query, (string) ($params['search'] ?? ''));
        $this->applySort($model, $query, (string) ($params['sort'] ?? ''));

        $columns = $this->projection($model, (string) ($params['fields'] ?? ''));

        $perPage = $this->perPage($params['per_page'] ?? null);

        $paginator = $query->paginate($perPage, $columns, 'page', (int) ($params['page'] ?? 1));

        $this->expand($model, $paginator->getCollection(), $this->expandKeys($params), $authorizeExpand);

        return $paginator;
    }

    /**
     * @param  array<string,mixed>  $params
     * @param  (callable(string,bool):(array{rule?:array<string,mixed>,fields?:array<int,string>|null}|false))|null  $authorizeExpand
     */
    public function find(PbModel $model, int|string $id, array $params = [], ?callable $authorizeExpand = null): ?Record
    {
        $columns = $this->projection($model, (string) ($params['fields'] ?? ''));

        /** @var Record|null $record */
        $record = Record::for($model)->newQuery()->find($id, $columns);

        if ($record !== null) {
            $this->expand($model, collect([$record]), $this->expandKeys($params), $authorizeExpand);
        }

        return $record;
    }

    /**
     * Parse `expand=a,b` (string or array) into a clean list of keys.
     *
     * @param  array<string,mixed>  $params
     * @return array<int,string>
     */
    private function expandKeys(array $params): array
    {
        $raw = $params['expand'] ?? '';
        $keys = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map('trim', $keys)));
    }

    /**
     * Resolve a relation field's `relation_model` target to a model instance —
     * either the app's users table (the `PbUser::RELATION_TARGET` sentinel) or
     * another collection's dynamic Record. Lets a user relation (author /
     * approver / assignee …) behave exactly like a collection relation for both
     * the exists-validation and the expand lookup.
     */
    private function relationTarget(string $relatedKey): Model
    {
        if ($relatedKey === PbUser::RELATION_TARGET) {
            /** @var class-string<PbUser> $userClass */
            $userClass = config('ai-page-builder.models.user', PbUser::class);

            return new $userClass;
        }

        return Record::for($relatedKey);
    }

    /**
     * Attach related records to a result set. Supports two directions, keyed by
     * the requested expand name:
     *   - belongs-to: a `relation` field's key (e.g. `manager`) → the single
     *     related row under that key (resolved from `{key}_id`);
     *   - has-many (reverse): another collection's key (e.g. `tasks`) whose
     *     `relation` field points back at this model → the array of child rows.
     * Batched (no N+1). Related rows are loaded through Record so casts apply.
     *
     * Permission-gated at the edge: when an `$authorize` callback is supplied
     * (the REST API passes one — flows/functions/admin omit it and stay trusted),
     * each expand target is checked first. The callback receives the related
     * collection key and whether the target is the users table, and returns
     * either `false` to drop the expand entirely, or `['rule' => <row filter>,
     * 'fields' => <allowed field keys|null>]` to scope the attached rows. The
     * row rule is applied as a query filter (cross-owner rows never load) and
     * the field list projects every attached related row (null = all fields).
     * User-target relations are always projected through PbUser's $hidden so
     * password / 2FA columns never leak.
     *
     * @param  Collection<int,Record>  $records
     * @param  array<int,string>  $expand
     * @param  (callable(string,bool):(array{rule?:array<string,mixed>,fields?:array<int,string>|null}|false))|null  $authorize
     */
    private function expand(PbModel $model, Collection $records, array $expand, ?callable $authorize = null): void
    {
        if ($expand === [] || $records->isEmpty()) {
            return;
        }

        $fields = $model->fields()->get();
        $relationFields = $fields->filter(fn ($f) => $f->fieldType() === FieldType::Relation)->keyBy('key');

        // `expand=*` — expand every belongs-to relation (used by the auto data table
        // so a list shows the related NAME, not a raw foreign-key id).
        if (in_array('*', $expand, true)) {
            $expand = array_values(array_unique(array_merge(
                array_values(array_diff($expand, ['*'])),
                $relationFields->keys()->all(),
            )));
        }

        /** @var class-string<PbModel> $pbModelClass */
        $pbModelClass = config('ai-page-builder.models.model', PbModel::class);

        foreach ($expand as $name) {
            // Forward belongs-to: `name` is a relation field on this model.
            if ($relationFields->has($name)) {
                $field = $relationFields->get($name);
                $relatedKey = $field->options['relation_model'] ?? null;
                $column = $field->columnName();

                if (! is_string($relatedKey) || $relatedKey === '') {
                    continue;
                }

                $isUser = $relatedKey === PbUser::RELATION_TARGET;

                // Edge authorization: drop the expand if the caller may not read
                // the related collection; otherwise capture its scope.
                $scope = $this->authorizeExpandTarget($authorize, $relatedKey, $isUser);
                if ($scope === false) {
                    foreach ($records as $record) {
                        $record->setAttribute($name, null);
                    }

                    continue;
                }

                $ids = $records->pluck($column)->filter()->unique()->values()->all();
                try {
                    $related = $ids === []
                        ? collect()
                        : $this->scopedRelatedQuery($relatedKey, $scope['rule'])->whereIn('id', $ids)->get();
                } catch (\Throwable) {
                    continue;
                }

                $relatedById = $related
                    ->map(fn (Model $row) => $this->projectRelated($row, $scope['fields']))
                    ->keyBy('id');

                // When the relation field is keyed `<x>_id` its key IS the fk column,
                // which carries an integer cast that would coerce the related array
                // back to an int. Attach the expanded row under the stripped key
                // (`<x>`) so it survives, leaving the id on `<x>_id`.
                $attachKey = ($name === $column && str_ends_with($name, '_id')) ? substr($name, 0, -3) : $name;
                foreach ($records as $record) {
                    $fk = $record->getAttribute($column);
                    $record->setAttribute($attachKey, $fk !== null ? $relatedById->get($fk) : null);
                }

                continue;
            }

            // Reverse has-many: `name` is another collection that has a relation
            // field pointing at THIS model.
            $childModel = $pbModelClass::query()->where('key', $name)->first();
            if ($childModel === null) {
                continue;
            }

            $backRef = $childModel->fields()->get()->first(
                fn ($f) => $f->fieldType() === FieldType::Relation && ($f->options['relation_model'] ?? null) === $model->key,
            );
            if ($backRef === null) {
                continue;
            }

            // Edge authorization for the child collection.
            $scope = $this->authorizeExpandTarget($authorize, $childModel->key, false);
            if ($scope === false) {
                foreach ($records as $record) {
                    $record->setAttribute($name, []);
                }

                continue;
            }

            $column = $backRef->columnName();
            $parentIds = $records->pluck('id')->all();
            try {
                $childQuery = Record::for($childModel)->newQuery()->whereIn($column, $parentIds);
                // Scope to the child collection's row rule (scalar => eq) through
                // applyFilters so it passes the model's column whitelist.
                $this->applyFilters($childModel, $childQuery, $scope['rule']);
                $childrenByParent = $childQuery->get()->groupBy($column);
            } catch (\Throwable) {
                continue;
            }

            foreach ($records as $record) {
                $children = $childrenByParent->get($record->getAttribute('id'));
                $record->setAttribute($name, $children
                    ? $children->map(fn (Record $row) => $this->projectRelated($row, $scope['fields']))->values()->all()
                    : []);
            }
        }
    }

    /**
     * Resolve the expand authorization callback to a concrete scope. No callback
     * (trusted caller) → full access (no rule, all fields). A callback returning
     * `false` propagates as `false` (drop the expand). Otherwise normalise the
     * returned scope so callers always get both keys.
     *
     * @param  (callable(string,bool):(array{rule?:array<string,mixed>,fields?:array<int,string>|null}|false))|null  $authorize
     * @return array{rule:array<string,mixed>,fields:array<int,string>|null}|false
     */
    private function authorizeExpandTarget(?callable $authorize, string $relatedKey, bool $isUser): array|false
    {
        if ($authorize === null) {
            return ['rule' => [], 'fields' => null];
        }

        $scope = $authorize($relatedKey, $isUser);
        if ($scope === false) {
            return false;
        }

        return [
            'rule' => is_array($scope['rule'] ?? null) ? $scope['rule'] : [],
            'fields' => array_key_exists('fields', $scope) ? $scope['fields'] : null,
        ];
    }

    /**
     * A query for the related target with the related collection's row rule
     * applied as filters, so cross-owner rows are never loaded into the expand.
     *
     * @param  array<string,mixed>  $rule
     */
    private function scopedRelatedQuery(string $relatedKey, array $rule): Builder
    {
        $query = $this->relationTarget($relatedKey)->newQuery();

        if ($rule !== []) {
            // Row rules are scalar field => value equality pairs.
            foreach ($rule as $field => $value) {
                $query->where($field, $value);
            }
        }

        return $query;
    }

    /**
     * Project an attached related row to a plain array, dropping any field the
     * caller may not see. Every row goes through the model's `toArray()`, so a
     * user-target row is already stripped of password / 2FA via PbUser's
     * $hidden; `id` is always kept so rows stay addressable.
     *
     * @param  array<int,string>|null  $fields
     * @return array<string,mixed>
     */
    private function projectRelated(Model $row, ?array $fields): array
    {
        $arr = $row->toArray();

        if ($fields === null) {
            return $arr;
        }

        return array_intersect_key($arr, array_flip(['id', ...$fields]));
    }

    /**
     * Server-side aggregation for charts / KPI cards. Fully column-whitelisted —
     * `field` and `group_by` must be real columns of the model, so nothing
     * user-supplied reaches the SQL as an identifier.
     *
     * @param  array<string,mixed>  $params  {
     *                                       metric: count|sum|avg|min|max (default count),
     *                                       field: <column> (required for sum/avg/min/max),
     *                                       group_by: <column> (omit for a single KPI number),
     *                                       date_bucket: day|week|month|year (when grouping a date column),
     *                                       filter: Directus-style, search: term,
     *                                       sort: value|-value|label|-label (default -value), limit: int (<=500)
     *                                       }
     * @return array{metric:string,total:float,rows:list<array{label:?string,value:float}>}
     */
    public function aggregate(PbModel $model, array $params = []): array
    {
        $columns = $this->columns($model);

        $metric = strtolower((string) ($params['metric'] ?? 'count'));
        if (! in_array($metric, ['count', 'sum', 'avg', 'min', 'max'], true)) {
            $metric = 'count';
        }

        $query = Record::for($model)->newQuery();
        $this->applyFilters($model, $query, (array) ($params['filter'] ?? []));
        $this->applySearch($model, $query, (string) ($params['search'] ?? ''));

        $grammar = $query->getQuery()->getGrammar();

        // The metric expression. count → COUNT(*); the rest need a valid column.
        if ($metric === 'count') {
            $valueExpr = 'count(*)';
        } else {
            $field = (string) ($params['field'] ?? '');
            if (! in_array($field, $columns, true)) {
                return ['metric' => $metric, 'total' => 0.0, 'rows' => []];
            }
            $valueExpr = strtoupper($metric).'('.$grammar->wrap($field).')';
        }

        $groupBy = (string) ($params['group_by'] ?? '');

        // No grouping → a single KPI number.
        if ($groupBy === '' || ! in_array($groupBy, $columns, true)) {
            $value = (float) ($query->selectRaw($valueExpr.' as aggregate')->value('aggregate') ?? 0);

            return ['metric' => $metric, 'total' => $value, 'rows' => [['label' => null, 'value' => $value]]];
        }

        // Group key — optionally bucket a date/datetime column by period.
        $bucket = strtolower((string) ($params['date_bucket'] ?? ''));
        $labelExpr = in_array($bucket, ['day', 'week', 'month', 'year'], true)
            ? $this->dateBucketExpr($query, $grammar->wrap($groupBy), $bucket)
            : $grammar->wrap($groupBy);

        $rows = $query
            ->selectRaw($labelExpr.' as label')
            ->selectRaw($valueExpr.' as value')
            ->groupByRaw($labelExpr)
            ->get();

        $rows = $rows->map(fn (Record $r) => [
            'label' => $r->getAttribute('label') === null ? null : (string) $r->getAttribute('label'),
            'value' => (float) $r->getAttribute('value'),
        ])->all();

        // Sort + limit.
        $sort = (string) ($params['sort'] ?? '-value');
        $key = ltrim($sort, '-');
        $desc = str_starts_with($sort, '-');
        usort($rows, function (array $a, array $b) use ($key, $desc): int {
            $cmp = $key === 'label'
                ? strcmp((string) $a['label'], (string) $b['label'])
                : $a['value'] <=> $b['value'];

            return $desc ? -$cmp : $cmp;
        });

        $limit = max(1, min(500, (int) ($params['limit'] ?? 50)));
        $rows = array_slice($rows, 0, $limit);

        return [
            'metric' => $metric,
            'total' => array_sum(array_column($rows, 'value')),
            'rows' => array_values($rows),
        ];
    }

    /**
     * Driver-aware date-bucket SQL for a (already-quoted) column. Supports the
     * three drivers the package targets; falls back to the raw column elsewhere.
     */
    private function dateBucketExpr(Builder $query, string $wrappedColumn, string $bucket): string
    {
        /** @var Connection $connection */
        $connection = $query->getConnection();
        $driver = $connection->getDriverName();

        return match ($driver) {
            'sqlite' => match ($bucket) {
                'day' => "strftime('%Y-%m-%d', {$wrappedColumn})",
                'week' => "strftime('%Y-W%W', {$wrappedColumn})",
                'month' => "strftime('%Y-%m', {$wrappedColumn})",
                'year' => "strftime('%Y', {$wrappedColumn})",
                default => $wrappedColumn,
            },
            'pgsql' => "to_char({$wrappedColumn}, ".match ($bucket) {
                'day' => "'YYYY-MM-DD'",
                'week' => "'IYYY-\"W\"IW'",
                'month' => "'YYYY-MM'",
                'year' => "'YYYY'",
                default => "'YYYY-MM-DD'",
            }.')',
            default => 'DATE_FORMAT('.$wrappedColumn.', '.match ($bucket) { // mysql/mariadb
                'day' => "'%Y-%m-%d'",
                'week' => "'%x-W%v'",
                'month' => "'%Y-%m'",
                'year' => "'%Y'",
                default => "'%Y-%m-%d'",
            }.')',
        };
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(PbModel $model, array $data): Record
    {
        $clean = $this->validate($model, $data, partial: false);

        $record = Record::for($model);
        $record->fill($clean)->save();

        $this->recordRevision($model, RecordRevision::OP_CREATED, $record->getKey(), null, $record->toArray());

        return $record;
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(PbModel $model, int|string $id, array $data): ?Record
    {
        $record = Record::for($model)->newQuery()->find($id);

        if ($record === null) {
            return null;
        }

        $before = $record->toArray();

        $clean = $this->validate($model, $data, partial: true);
        $record->fill($clean)->save();

        $this->recordRevision($model, RecordRevision::OP_UPDATED, $record->getKey(), $before, $record->toArray());

        return $record;
    }

    public function delete(PbModel $model, int|string $id): bool
    {
        $record = Record::for($model)->newQuery()->find($id);

        if ($record === null) {
            return false;
        }

        $before = $record->toArray();

        $deleted = (bool) $record->delete();

        if ($deleted) {
            $this->recordRevision($model, RecordRevision::OP_DELETED, $id, $before, null);
        }

        return $deleted;
    }

    /**
     * Validate + map an input payload to physical columns, keeping only defined
     * fields. Input may be keyed by a field's key or its column name (relations
     * accept either `manager` or `manager_id`).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    public function validate(PbModel $model, array $data, bool $partial = false): array
    {
        $rules = [];
        $mapped = [];

        foreach ($model->fields()->get() as $field) {
            $type = $field->fieldType();
            $column = $type->columnName($field->key);

            $present = array_key_exists($field->key, $data) || array_key_exists($column, $data);
            if ($partial && ! $present) {
                continue;
            }

            $value = $data[$field->key] ?? $data[$column] ?? null;
            $mapped[$column] = $value;

            $fieldRules = $type->validationRules((array) ($field->options ?? []));

            // Referential integrity: a relation value must reference a real row
            // in the related collection's table.
            if ($type === FieldType::Relation) {
                $relatedKey = $field->options['relation_model'] ?? null;
                if (is_string($relatedKey) && $relatedKey !== '') {
                    try {
                        $related = $this->relationTarget($relatedKey);
                        $conn = $related->getConnectionName();
                        $fieldRules[] = 'exists:'.($conn ? $conn.'.' : '').$related->getTable().',id';
                    } catch (\Throwable) {
                        // Related target not resolvable — skip the exists check.
                    }
                }
            }

            if ($partial) {
                $fieldRules = array_values(array_diff($fieldRules, ['required']));
                array_unshift($fieldRules, 'sometimes');
            }
            $rules[$column] = $fieldRules;
        }

        return Validator::make($mapped, $rules)->validate();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyFilters(PbModel $model, Builder $query, array $filters): void
    {
        $columns = $this->columns($model);

        foreach ($filters as $column => $spec) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            $conditions = is_array($spec) ? $spec : ['eq' => $spec];

            foreach ($conditions as $op => $value) {
                $this->applyCondition($query, $column, (string) $op, $value);
            }
        }
    }

    private function applyCondition(Builder $query, string $column, string $op, mixed $value): void
    {
        match ($op) {
            'in' => $query->whereIn($column, $this->toList($value)),
            'nin' => $query->whereNotIn($column, $this->toList($value)),
            'null' => $query->whereNull($column),
            'nnull' => $query->whereNotNull($column),
            'between' => $query->whereBetween($column, array_slice($this->toList($value), 0, 2)),
            'like' => $query->where($column, 'like', '%'.$value.'%'),
            default => isset(self::OPERATORS[$op])
                ? $query->where($column, self::OPERATORS[$op], $value)
                : null,
        };
    }

    private function applySearch(PbModel $model, Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $textColumns = $model->fields()->get()
            ->filter(fn ($f) => in_array($f->type, ['string', 'text', 'select'], true))
            ->map(fn ($f) => $f->columnName())
            ->all();

        if ($textColumns === []) {
            return;
        }

        $query->where(function (Builder $q) use ($textColumns, $term): void {
            foreach ($textColumns as $column) {
                $q->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    private function applySort(PbModel $model, Builder $query, string $sort): void
    {
        if ($sort === '') {
            $query->orderBy('id');

            return;
        }

        $columns = $this->columns($model);

        foreach (explode(',', $sort) as $token) {
            $token = trim($token);
            $direction = str_starts_with($token, '-') ? 'desc' : 'asc';
            $column = ltrim($token, '-');

            if (in_array($column, $columns, true)) {
                $query->orderBy($column, $direction);
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function projection(PbModel $model, string $fields): array
    {
        if ($fields === '') {
            return ['*'];
        }

        $columns = $this->columns($model);
        $selected = array_values(array_filter(
            array_map('trim', explode(',', $fields)),
            fn (string $c) => in_array($c, $columns, true),
        ));

        if ($selected === []) {
            return ['*'];
        }

        if (! in_array('id', $selected, true)) {
            array_unshift($selected, 'id');
        }

        return $selected;
    }

    /**
     * Whitelist of queryable columns for a model (id + field columns + system).
     *
     * @return array<int,string>
     */
    private function columns(PbModel $model): array
    {
        $columns = ['id'];

        foreach ($model->fields()->get() as $field) {
            $columns[] = $field->columnName();
        }

        if ($model->has_timestamps) {
            $columns[] = 'created_at';
            $columns[] = 'updated_at';
        }
        if ($model->has_soft_deletes) {
            $columns[] = 'deleted_at';
        }

        return $columns;
    }

    /**
     * @return array<int,mixed>
     */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return array_map('trim', explode(',', (string) $value));
    }

    private function perPage(mixed $requested): int
    {
        $default = (int) config('ai-page-builder.data.default_per_page', 25);
        $max = (int) config('ai-page-builder.data.max_per_page', 200);

        $perPage = $requested === null ? $default : (int) $requested;

        return max(1, min($perPage, $max));
    }

    /**
     * Snapshot a collection write into the record_revisions table. Gated by the
     * `data.record_history` flag and skipped for external / read-only
     * collections (the package doesn't own their writes). The acting pb-guard
     * end-user stamps `changed_by` when present. Resilient by design: a
     * revision-write failure is logged and swallowed so it can never break the
     * actual record write.
     *
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     */
    private function recordRevision(
        PbModel $model,
        string $operation,
        int|string|null $recordId,
        ?array $before,
        ?array $after,
    ): void {
        if (! (bool) config('ai-page-builder.data.record_history', true)) {
            return;
        }

        // Only managed, writable collections are snapshotted.
        if ($model->isExternal() || $model->isReadOnly()) {
            return;
        }

        if ($recordId === null) {
            return;
        }

        try {
            /** @var class-string<RecordRevision> $revisionClass */
            $revisionClass = config('ai-page-builder.models.record_revision', RecordRevision::class);

            $revisionClass::query()->create([
                'collection' => $model->key,
                'record_id' => (string) $recordId,
                'operation' => $operation,
                'before' => $before,
                'after' => $after,
                'changed_by' => $this->actingUserId(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ai-page-builder: failed to write record revision', [
                'collection' => $model->key,
                'operation' => $operation,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The acting pb-guard end-user's id, or null when unauthenticated / auth off.
     */
    private function actingUserId(): ?int
    {
        if (! (bool) config('ai-page-builder.auth.enabled', true)) {
            return null;
        }

        $guard = (string) config('ai-page-builder.auth.guard', 'pb');
        $user = Auth::guard($guard)->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
