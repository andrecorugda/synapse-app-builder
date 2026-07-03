<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * A persistent, app-wide key→value pair (a "global"). Distinct from a flow
 * run's ephemeral FlowContext.vars: these survive across runs and are readable
 * /writable from flows, functions, and the Filament admin.
 *
 * The raw `value` is always stored as a string; `type` records how to cast it
 * back when read (see typedValue / castForStorage).
 *
 * @property int $id
 * @property string $key
 * @property string $type 'string'|'number'|'boolean'|'json'
 * @property string|null $value
 * @property string|null $description
 * @property bool $is_protected
 */
class Variable extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_protected' => 'boolean',
        // Nested typed schema for an Object state: [{name,type,fields?/ref?}, …].
        'shape' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('variables');
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * The stored string `value` cast back to its declared `type`.
     */
    public function typedValue(): mixed
    {
        $raw = $this->value;

        if ($raw === null) {
            return null;
        }

        return match ($this->type) {
            'number' => $this->castNumber($raw),
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($raw, true),
            default => $raw,
        };
    }

    /**
     * The native typed value for a raw input + type, matching exactly what a
     * later read of the persisted variable returns (castForStorage → typedValue
     * round-trip). Callers use this to cast BEFORE persisting so the in-memory
     * copy they keep (a flow's setState action, an output var) carries the same
     * type as the stored State — not the raw pre-cast string.
     */
    public static function toTyped(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return (new self(['type' => $type, 'value' => self::castForStorage($value, $type)]))->typedValue();
    }

    /**
     * Serialize a value for storage in the string `value` column per `type`.
     */
    public static function castForStorage(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'json') {
            return (string) json_encode($value);
        }

        if ($type === 'boolean') {
            // Interpret the value the way a human means it: a real bool, an int,
            // or a STRING like "true"/"false"/"1"/"0"/"yes"/"no"/"on"/"off".
            // A plain `$value ? …` would store the string "false" as TRUE (any
            // non-empty string is truthy in PHP) — so a boolean var set to
            // "false" silently became true.
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return (string) $value;
    }

    private function castNumber(string $raw): int|float
    {
        return str_contains($raw, '.') || str_contains($raw, 'e') || str_contains($raw, 'E')
            ? (float) $raw
            : (int) $raw;
    }
}
