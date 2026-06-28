<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * A single app-wide configuration entry for the builder (home page, email /
 * SMTP config, …). Distinct from a Variable: variables are app *data* the
 * builder author exposes to flows/pages; settings configure the builder itself.
 *
 * The `value` column holds a JSON-encoded payload; the Settings service is the
 * only thing that reads/writes it, so callers always deal in native PHP values.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
class PbSetting extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('settings');
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
