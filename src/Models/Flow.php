<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A flow: a trigger + a graph of nodes (the orchestration "brain").
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $trigger_type
 * @property bool $is_active
 * @property bool $is_public
 * @property ?int $rate_limit_per_minute
 * @property ?array $definition
 */
class Flow extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'rate_limit_per_minute' => 'integer',
        'trigger_config' => 'array',
        'definition' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('flows');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Telemetry runs for this flow (newest first). Shown as a tab on the flow. */
    public function runs(): HasMany
    {
        /** @var class-string<Model> $model */
        $model = config('ai-page-builder.models.flow_run', FlowRun::class);

        return $this->hasMany($model, 'flow_id')->latest();
    }

    /**
     * @param  Builder<Flow>  $query
     * @return Builder<Flow>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
