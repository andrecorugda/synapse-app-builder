<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending invitation for someone to join the built app as an end-user. The
 * admin creates one (email + role); the invitee follows the emailed link to set
 * a password and activate. The token is stored hashed; status is derived from
 * accepted_at / expires_at.
 *
 * @property int $id
 * @property string $email
 * @property string $token
 * @property int|null $role_id
 * @property int|null $invited_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 */
class PbUserInvite extends Model
{
    protected $guarded = [];

    protected $casts = [
        'role_id' => 'integer',
        'invited_by' => 'integer',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('user_invites');
    }

    /**
     * @return BelongsTo<PbRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(PbRole::class, 'role_id');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /** Derived status label for the admin list. */
    public function statusLabel(): string
    {
        return $this->isAccepted() ? 'accepted' : ($this->isExpired() ? 'expired' : 'pending');
    }
}
