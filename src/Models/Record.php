<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * A dynamic Eloquent model over a user-defined data model's REAL table. Resolve
 * one with Record::for('leads') (or a PbModel instance); the returned instance
 * carries the right table, connection, timestamps flag, and casts, and
 * propagates them to every instance hydrated from its queries — so
 * Record::for('leads')->newQuery()->where(...)->get() returns correctly-cast
 * Record rows. Used by the REST API, the Flow Record node, and Functions.
 */
class Record extends Model
{
    protected $guarded = [];

    public $timestamps = true;

    /** The collection key this record belongs to (for reference / events). */
    public ?string $pbModelKey = null;

    /**
     * Build a Record bound to a collection's physical table.
     */
    public static function for(PbModel|string $model): static
    {
        $pb = $model instanceof PbModel
            ? $model
            : static::resolvePbModel($model);

        $instance = new static;
        $instance->setConnection(Schema::connection());
        $instance->setTable($pb->table_name);
        $instance->timestamps = (bool) $pb->has_timestamps;
        $instance->pbModelKey = $pb->key;
        $instance->mergeCasts($pb->fieldCasts());

        return $instance;
    }

    /**
     * Carry the dynamic table/connection/casts to every hydrated child instance
     * (newFromBuilder routes through here), so query results keep the right
     * table identity instead of resetting to the bare class defaults.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function newInstance($attributes = [], $exists = false): static
    {
        /** @var static $model */
        $model = parent::newInstance($attributes, $exists);
        $model->setTable($this->getTable());
        $model->setConnection($this->getConnectionName());
        $model->timestamps = $this->timestamps;
        $model->pbModelKey = $this->pbModelKey;
        $model->mergeCasts($this->casts);

        return $model;
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? Schema::connection();
    }

    private static function resolvePbModel(string $key): PbModel
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        /** @var PbModel */
        return $modelClass::query()->where('key', $key)->firstOrFail();
    }
}
