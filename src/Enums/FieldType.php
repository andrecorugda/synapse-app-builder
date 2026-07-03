<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Enums;

use Illuminate\Database\Schema\Blueprint;

/**
 * The field types a user-defined data model (collection) supports. This enum is
 * the single source of truth that maps a logical type to (1) its physical
 * column on the generated table, (2) its Eloquent cast, and (3) its validation
 * rule — so the schema synchronizer, the dynamic Record model, and the REST/
 * Filament validation all stay in agreement.
 */
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Json = 'json';
    case Select = 'select';
    case Relation = 'relation';
    case Image = 'image';

    public function label(): string
    {
        return match ($this) {
            self::String => 'Text (single line)',
            self::Text => 'Text (multi-line)',
            self::Integer => 'Integer',
            self::Decimal => 'Decimal',
            self::Boolean => 'Boolean',
            self::Date => 'Date',
            self::DateTime => 'Date & time',
            self::Json => 'JSON',
            self::Select => 'Select (choices)',
            self::Relation => 'Relation (belongs to)',
            self::Image => 'Image',
        };
    }

    /**
     * The physical column name for a field on the generated table. Relations
     * store a foreign id as `{key}_id`; everything else uses the key verbatim.
     *
     * Idempotent on the `_id` suffix: a relation field keyed `customer` becomes
     * `customer_id`, but one already keyed `customer_id` stays `customer_id`
     * (NOT `customer_id_id`). Authors — and the AI, prompted with "product
     * relation" — naturally name the field `product_id`; without this guard that
     * doubled to `product_id_id`, and every write to `product_id` hit a phantom
     * column while the real NOT-NULL fk went unfilled.
     */
    public function columnName(string $key): string
    {
        if ($this !== self::Relation) {
            return $key;
        }

        return str_ends_with($key, '_id') ? $key : $key.'_id';
    }

    /**
     * Define this field's column on a create/alter Blueprint. Honours the
     * field's `options` (length, precision, default, nullable, unique, index).
     *
     * When $change is true the column is ALTERed in place (`->change()`) to
     * migrate an existing field to a new type — the type, nullability and
     * default are re-applied but index/unique declarations are NOT, because
     * `change()` doesn't reconcile indexes (re-adding an existing unique index
     * would error). Index changes remain a create/destructive-sync concern.
     *
     * @param  array<string,mixed>  $options
     */
    public function defineColumn(Blueprint $table, string $key, array $options = [], bool $change = false): void
    {
        $name = $this->columnName($key);
        $nullable = (bool) ($options['nullable'] ?? ! ($options['required'] ?? false));

        $column = match ($this) {
            self::String, self::Select => $table->string($name, (int) ($options['length'] ?? 255)),
            self::Text => $table->text($name),
            self::Integer => $table->bigInteger($name),
            self::Decimal => $table->decimal($name, (int) ($options['precision'] ?? 12), (int) ($options['scale'] ?? 2)),
            self::Boolean => $table->boolean($name),
            self::Date => $table->date($name),
            self::DateTime => $table->dateTime($name),
            self::Json => $table->json($name),
            self::Relation => $table->unsignedBigInteger($name),
            self::Image => $table->string($name, 2048),
        };

        $column->nullable($nullable);

        if (array_key_exists('default', $options) && $options['default'] !== null && $options['default'] !== '') {
            $column->default($this->castDefault($options['default']));
        }

        if ($change) {
            $column->change();

            return;
        }

        if (! empty($options['unique'])) {
            $column->unique();
        } elseif (! empty($options['index']) || $this === self::Relation) {
            $column->index();
        }
    }

    /**
     * Coarse storage category, used to decide whether an existing column needs
     * an ALTER when its field's type changed. Types that share physical storage
     * collapse to one category so a no-op edit (e.g. text↔json on SQLite, or
     * integer↔relation) never triggers a spurious ALTER. Distinct categories
     * (string→integer, integer→boolean, …) are what actually require a change.
     */
    public function storageCategory(): string
    {
        return match ($this) {
            self::String, self::Select, self::Image => 'string',
            self::Text, self::Json => 'text',
            self::Integer, self::Relation => 'integer',
            self::Decimal => 'decimal',
            self::Boolean => 'boolean',
            self::Date => 'date',
            self::DateTime => 'datetime',
        };
    }

    /**
     * Normalise a driver-reported column type (SQLite/MySQL/Postgres) into the
     * same coarse categories as {@see storageCategory()}. Returns 'unknown' for
     * anything unrecognised so the synchronizer stays conservative (no ALTER on
     * a type it can't classify). Order matters: `tinyint` (boolean) is tested
     * before the generic `int`, and `text`/`json` before other matches.
     */
    public static function normalizeDbType(string $dbType): string
    {
        $t = strtolower($dbType);

        return match (true) {
            str_contains($t, 'char') => 'string',
            str_contains($t, 'text'), str_contains($t, 'clob'), str_contains($t, 'json') => 'text',
            str_contains($t, 'tinyint'), str_contains($t, 'bool') => 'boolean',
            str_contains($t, 'int') => 'integer',
            str_contains($t, 'decimal'), str_contains($t, 'numeric'), str_contains($t, 'float'), str_contains($t, 'double'), str_contains($t, 'real') => 'decimal',
            str_contains($t, 'datetime'), str_contains($t, 'timestamp') => 'datetime',
            str_contains($t, 'date') => 'date',
            default => 'unknown',
        };
    }

    /** The Eloquent cast for this type (null = leave as string). */
    public function cast(): ?string
    {
        return match ($this) {
            self::Integer, self::Relation => 'integer',
            self::Decimal => 'float',
            self::Boolean => 'boolean',
            self::Date => 'date',
            self::DateTime => 'datetime',
            self::Json => 'array',
            default => null,
        };
    }

    /**
     * Laravel validation rules for a record value of this type.
     *
     * @param  array<string,mixed>  $options
     * @return array<int,string>
     */
    public function validationRules(array $options = []): array
    {
        $rules = [($options['required'] ?? false) ? 'required' : 'nullable'];

        $rules[] = match ($this) {
            self::String => 'string',
            self::Text => 'string',
            self::Integer, self::Relation => 'integer',
            self::Decimal => 'numeric',
            self::Boolean => 'boolean',
            self::Date, self::DateTime => 'date',
            self::Json => 'array',
            self::Select => 'string',
            self::Image => 'string',
        };

        if ($this === self::String || $this === self::Select) {
            $rules[] = 'max:'.(int) ($options['length'] ?? 255);
        }

        if ($this === self::Select && ! empty($options['choices']) && is_array($options['choices'])) {
            $rules[] = 'in:'.implode(',', array_map('strval', $options['choices']));
        }

        return $rules;
    }

    private function castDefault(mixed $value): mixed
    {
        return match ($this) {
            self::Integer, self::Relation => (int) $value,
            self::Decimal => (float) $value,
            self::Boolean => (bool) $value,
            default => $value,
        };
    }
}
