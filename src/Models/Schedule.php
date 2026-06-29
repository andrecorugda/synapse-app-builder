<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Services\ScheduleRunner;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A UI-defined scheduled job. Each row pairs a cron expression with a target
 * (a Flow or a Function, addressed by slug) that runs when the expression is
 * due. The package's `ai-page-builder:run-schedules` command — scheduled every
 * minute by the service provider — evaluates due rows via {@see ScheduleRunner}.
 *
 * @property int $id
 * @property string $name
 * @property string $cron_expression
 * @property string $target_type 'flow'|'function'
 * @property string $target_key
 * @property ?array $args
 * @property ?string $timezone
 * @property bool $is_active
 * @property ?Carbon $last_run_at
 * @property ?string $last_status 'ok'|'failed'
 * @property ?string $last_error
 */
class Schedule extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'args' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('schedules');
    }

    /**
     * @param  Builder<Schedule>  $query
     * @return Builder<Schedule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
