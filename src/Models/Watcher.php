<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Flow\RecordObserver;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A reactive trigger binding: one source event → one target (Flow or Function).
 *
 * Watchers decouple "what fires a flow" from the flow graph itself, so a single
 * reusable flow can be invoked from many sources, and — crucially — each
 * collection event (created / updated / deleted) can target a *different* flow.
 * Two source kinds:
 *   • collection — fires when a record in a collection is created/updated/deleted
 *     (via {@see RecordObserver}).
 *   • state — fires when a global variable changes (via
 *     {@see VariableStore::set}).
 *
 * @property int $id
 * @property string $name
 * @property string $source_type 'collection'|'state'
 * @property string $source_key
 * @property ?string $event 'created'|'updated'|'deleted'|'changed'
 * @property ?array $config
 * @property string $target_type 'flow'|'function'
 * @property string $target_key
 * @property ?array $input_map
 * @property bool $is_active
 * @property ?Carbon $last_fired_at
 * @property ?string $last_status 'ok'|'failed'
 * @property ?string $last_error
 */
class Watcher extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
        'input_map' => 'array',
        'is_active' => 'boolean',
        'last_fired_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('watchers');
    }

    /**
     * @param  Builder<Watcher>  $query
     * @return Builder<Watcher>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Watcher>  $query
     * @return Builder<Watcher>
     */
    public function scopeForCollection(Builder $query, string $collectionKey, string $event): Builder
    {
        return $query->where('source_type', 'collection')
            ->where('source_key', $collectionKey)
            ->where('event', $event);
    }

    /**
     * @param  Builder<Watcher>  $query
     * @return Builder<Watcher>
     */
    public function scopeForState(Builder $query, string $stateKey): Builder
    {
        return $query->where('source_type', 'state')
            ->where('source_key', $stateKey);
    }
}
