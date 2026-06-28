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
        };
    }

    /**
     * The physical column name for a field on the generated table. Relations
     * store a foreign id as `{key}_id`; everything else uses the key verbatim.
     */
    public function columnName(string $key): string
    {
        return $this === self::Relation ? $key.'_id' : $key;
    }

    /**
     * Define this field's column on a create/alter Blueprint. Honours the
     * field's `options` (length, precision, default, nullable, unique, index).
     *
     * @param  array<string,mixed>  $options
     */
    public function defineColumn(Blueprint $table, string $key, array $options = []): void
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
        };

        $column->nullable($nullable);

        if (array_key_exists('default', $options) && $options['default'] !== null && $options['default'] !== '') {
            $column->default($this->castDefault($options['default']));
        }

        if (! empty($options['unique'])) {
            $column->unique();
        } elseif (! empty($options['index']) || $this === self::Relation) {
            $column->index();
        }
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
