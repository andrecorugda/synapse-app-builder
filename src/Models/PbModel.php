<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user-defined data model (a "collection"). Its metadata describes a REAL
 * database table (Directus-style) created/kept-in-sync by SchemaSynchronizer.
 * The physical table is `{data.table_prefix}{key}` so generated tables never
 * collide with the host app's own tables.
 *
 * @property int $id
 * @property string $key
 * @property string $table_name
 * @property string $name
 * @property string|null $label_singular
 * @property string|null $label_plural
 * @property string|null $description
 * @property string|null $icon
 * @property bool $has_timestamps
 * @property bool $has_soft_deletes
 * @property string $source_type
 * @property string|null $source_connection
 * @property bool $is_read_only
 * @property array<string,mixed>|null $options
 * @property int|null $created_by
 * @property Collection<int,PbField> $fields
 */
class PbModel extends Model
{
    use SoftDeletes;

    /** A managed collection owns its table; an external one maps to an existing table. */
    public const SOURCE_MANAGED = 'managed';

    public const SOURCE_EXTERNAL = 'external';

    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'has_timestamps' => 'boolean',
        'has_soft_deletes' => 'boolean',
        'is_read_only' => 'boolean',
        'created_by' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    /** True when this collection maps to an existing table the package does not manage. */
    public function isExternal(): bool
    {
        return $this->getAttribute('source_type') === self::SOURCE_EXTERNAL;
    }

    /** Writes blocked through the API (always true for read-only collections). */
    public function isReadOnly(): bool
    {
        return (bool) $this->getAttribute('is_read_only');
    }

    /**
     * The connection records live on: the configured external connection for an
     * external collection, else the package's own data connection.
     */
    public function dataConnection(): ?string
    {
        if ($this->isExternal()) {
            $conn = (string) ($this->getAttribute('source_connection') ?? '');

            return $conn !== '' ? $conn : Schema::connection();
        }

        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('models');
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** @return HasMany<PbField, $this> */
    public function fields(): HasMany
    {
        /** @var class-string<PbField> $fieldClass */
        $fieldClass = config('ai-page-builder.models.field', PbField::class);

        return $this->hasMany($fieldClass, 'model_id')->orderBy('sort');
    }

    /**
     * Resolve the physical (prefixed) table name for a given collection key.
     */
    public static function physicalTableName(string $key): string
    {
        $prefix = (string) config('ai-page-builder.data.table_prefix', 'pb_');

        return $prefix.str_replace('-', '_', $key);
    }

    /**
     * Column => Eloquent cast map derived from this model's field definitions.
     * Drives the dynamic Record model's casts.
     *
     * @return array<string,string>
     */
    public function fieldCasts(): array
    {
        $casts = [];

        foreach ($this->fields as $field) {
            $type = $field->fieldType();
            $cast = $type->cast();

            if ($cast !== null) {
                $casts[$type->columnName($field->key)] = $cast;
            }
        }

        return $casts;
    }
}
