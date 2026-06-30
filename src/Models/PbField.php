<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One field on a user-defined data model. `type` is a FieldType value; `options`
 * carries per-field config (required, unique, default, length, choices,
 * relation_model, …) consumed by FieldType when building the column / casts /
 * validation rules.
 *
 * @property int $id
 * @property int $model_id
 * @property string $key
 * @property string $label
 * @property string $type
 * @property array<string,mixed>|null $options
 * @property int $sort
 */
class PbField extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'sort' => 'integer',
    ];

    /**
     * Field key charset. A field key becomes a physical column name via
     * SchemaSynchronizer/FieldType, so — like a collection key — it is a DDL
     * injection surface on the untrusted AI/import path. Enforce it at the model
     * layer. Mirrors PbModel::KEY_PATTERN and BuildPlanValidator::isValidSlug.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    protected static function booted(): void
    {
        static::saving(static function (PbField $field): void {
            $key = $field->getAttribute('key');
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException(
                    "Invalid field key '".(is_string($key) ? $key : gettype($key))
                    ."': must match ".self::KEY_PATTERN.' (lowercase letter, then letters/digits/underscore).'
                );
            }
        });
    }

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('fields');
    }

    /** @return BelongsTo<PbModel, $this> */
    public function model(): BelongsTo
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        return $this->belongsTo($modelClass, 'model_id');
    }

    public function fieldType(): FieldType
    {
        return FieldType::tryFrom($this->type) ?? FieldType::String;
    }

    public function columnName(): string
    {
        return $this->fieldType()->columnName($this->key);
    }
}
