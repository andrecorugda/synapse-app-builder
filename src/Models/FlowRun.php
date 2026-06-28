<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Telemetry for one flow execution.
 *
 * @property int $id
 * @property int $flow_id
 * @property string $status
 * @property ?array $input
 * @property ?array $result
 * @property ?array $steps
 * @property ?string $error
 * @property ?int $duration_ms
 */
class FlowRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'steps' => 'array',
        'duration_ms' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('flow_runs');
    }
}
