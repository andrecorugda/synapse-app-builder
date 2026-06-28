<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable function that can be called from a `function` flow node.
 *
 * Two runtimes are supported:
 *   - expression: body is a Symfony ExpressionLanguage expression (sandboxed)
 *   - callable:   body is the key of a developer-registered native PHP callable
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $runtime 'expression'|'callable'
 * @property string|null $body
 * @property int|null $created_by
 */
class FlowFunction extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'created_by' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('functions');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
