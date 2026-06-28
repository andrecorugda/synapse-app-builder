<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An end-user of the BUILT app — the person who logs into the published site /
 * internal tool through the static login page. Authenticated via the package's
 * own `pb` guard, NOT the host app's user model.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int|null $role_id
 * @property bool $is_active
 */
class PbUser extends Authenticatable implements AuthenticatableContract
{
    use Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('users');
    }

    /**
     * @return BelongsTo<PbRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(PbRole::class, 'role_id');
    }

    /** True for users whose role is flagged is_admin (full access). */
    public function isAdmin(): bool
    {
        return (bool) $this->role?->is_admin;
    }
}
