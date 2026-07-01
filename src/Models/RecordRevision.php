<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of one write to a user-defined collection record.
 * Written by RecordQuery on every create/update/delete (gated by
 * `data.record_history`), it lets an admin browse — and optionally restore —
 * a record's history.
 *
 * `before`/`after` hold the record's full attribute array around the change:
 *   created → after only; updated → before + after; deleted → before only.
 *
 * @property int $id
 * @property string $collection
 * @property string $record_id
 * @property string $operation
 * @property array<string,mixed>|null $before
 * @property array<string,mixed>|null $after
 * @property int|null $changed_by
 * @property Carbon|null $created_at
 */
class RecordRevision extends Model
{
    public const OP_CREATED = 'created';

    public const OP_UPDATED = 'updated';

    public const OP_DELETED = 'deleted';

    protected $guarded = [];

    /** Revisions are append-only: only a created_at is stamped, never updated. */
    public const UPDATED_AT = null;

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'changed_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('record_revisions');
    }
}
