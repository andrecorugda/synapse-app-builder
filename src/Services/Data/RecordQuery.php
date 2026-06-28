<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services\Data;

use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
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
    public function list(PbModel $model, array $params = []): LengthAwarePaginator
    {
        $query = Record::for($model)->newQuery();

        $this->applyFilters($model, $query, (array) ($params['filter'] ?? []));
        $this->applySearch($model, $query, (string) ($params['search'] ?? ''));
        $this->applySort($model, $query, (string) ($params['sort'] ?? ''));

        $columns = $this->projection($model, (string) ($params['fields'] ?? ''));

        $perPage = $this->perPage($params['per_page'] ?? null);

        return $query->paginate($perPage, $columns, 'page', (int) ($params['page'] ?? 1));
    }

    /**
     * @param  array<string,mixed>  $params
     */
    public function find(PbModel $model, int|string $id, array $params = []): ?Record
    {
        $columns = $this->projection($model, (string) ($params['fields'] ?? ''));

        /** @var Record|null */
        return Record::for($model)->newQuery()->find($id, $columns);
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

        $clean = $this->validate($model, $data, partial: true);
        $record->fill($clean)->save();

        return $record;
    }

    public function delete(PbModel $model, int|string $id): bool
    {
        $record = Record::for($model)->newQuery()->find($id);

        if ($record === null) {
            return false;
        }

        return (bool) $record->delete();
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
}
