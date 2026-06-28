<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services\Data;

use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps a user-defined model's REAL database table in sync with its field
 * definitions (Directus-style runtime DDL). Creating a model builds its table;
 * adding fields adds columns. Dropping columns for removed fields is gated
 * behind `data.allow_destructive_sync` so a mis-edit can't silently lose data.
 */
class SchemaSynchronizer
{
    /**
     * Create the model's physical table, or bring an existing one in line with
     * the current field set.
     */
    public function sync(PbModel $model): void
    {
        $builder = $this->builder();
        $fields = $model->fields()->get();

        if (! $builder->hasTable($model->table_name)) {
            $builder->create($model->table_name, function (Blueprint $table) use ($model, $fields): void {
                $table->id();
                foreach ($fields as $field) {
                    $field->fieldType()->defineColumn($table, $field->key, (array) ($field->options ?? []));
                }
                if ($model->has_timestamps) {
                    $table->timestamps();
                }
                if ($model->has_soft_deletes) {
                    $table->softDeletes();
                }
            });

            return;
        }

        $existing = $builder->getColumnListing($model->table_name);
        $desired = [];

        $builder->table($model->table_name, function (Blueprint $table) use ($model, $fields, $existing, &$desired): void {
            foreach ($fields as $field) {
                $column = $field->columnName();
                $desired[] = $column;

                if (! in_array($column, $existing, true)) {
                    $field->fieldType()->defineColumn($table, $field->key, (array) ($field->options ?? []));
                }
            }

            if ($model->has_timestamps && ! in_array('created_at', $existing, true)) {
                $table->timestamps();
            }
            if ($model->has_soft_deletes && ! in_array('deleted_at', $existing, true)) {
                $table->softDeletes();
            }
        });

        $this->dropRemovedColumns($model, $existing, $desired);
    }

    /**
     * Drop columns whose field definitions were removed — only when destructive
     * sync is explicitly enabled. System columns are always preserved.
     *
     * @param  array<int,string>  $existing
     * @param  array<int,string>  $desired
     */
    private function dropRemovedColumns(PbModel $model, array $existing, array $desired): void
    {
        if (! (bool) config('ai-page-builder.data.allow_destructive_sync', false)) {
            return;
        }

        $system = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $toDrop = array_values(array_diff($existing, $desired, $system));

        if ($toDrop === []) {
            return;
        }

        $this->builder()->table($model->table_name, function (Blueprint $table) use ($toDrop): void {
            $table->dropColumn($toDrop);
        });
    }

    public function dropTable(PbModel $model): void
    {
        $this->builder()->dropIfExists($model->table_name);
    }

    public function tableExists(PbModel $model): bool
    {
        return $this->builder()->hasTable($model->table_name);
    }

    private function builder(): Builder
    {
        return Schema::connection(PbSchema::connection());
    }
}
