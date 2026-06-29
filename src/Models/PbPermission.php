<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grant for a role: an action on a resource, with an optional row-level
 * rule. The whole access model is just rows of these — Directus-style.
 *
 *   resource_type: collection | page
 *   resource_key:  <collection key> | <page slug> | '*'
 *   action:        create | read | update | delete (collections) · view (pages) · '*'
 *   rule:          { "<field>": "$CURRENT_USER" | <value> }  // null = unrestricted
 *
 * @property int $id
 * @property int $role_id
 * @property string $resource_type
 * @property string $resource_key
 * @property string $action
 * @property array<string,mixed>|null $rule
 */
class PbPermission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rule' => 'array',
        'fields' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('permissions');
    }

    /**
     * @return BelongsTo<PbRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(PbRole::class, 'role_id');
    }
}
