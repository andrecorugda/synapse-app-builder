<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

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
 * @property string $status
 * @property Carbon|null $email_verified_at
 * @property string|null $two_factor_method
 * @property string|null $two_factor_secret
 * @property array<int,string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 */
class PbUser extends Authenticatable implements AuthenticatableContract
{
    use Notifiable;

    /**
     * Sentinel `relation_model` value marking a relation field that targets the
     * app's users table instead of another collection. A collection can carry
     * several user relations (author, approver, assignee…), each a foreign id to
     * a PbUser — so "ownership" is just a named relation, not a special column.
     */
    public const RELATION_TARGET = '__users';

    /** Account lifecycle (see add_auth_fields_to_page_builder_users_table). */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUSPENDED = 'suspended';

    protected $guarded = [];

    /**
     * Never serialized. Covers the credentials (password, remember token), the
     * whole two-factor state (the decrypted TOTP secret and recovery codes would
     * otherwise appear in plain text), the SSO link, and personal / access data
     * (email, role) — so a PbUser that reaches JSON by any route (an API expand,
     * a helper, a flow output) carries none of it. Code that needs one of these
     * reads the attribute directly.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_method',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'provider',
        'provider_id',
        'email',
        'role_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'password' => 'hashed',
        'email_verified_at' => 'datetime',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    /**
     * API tokens die with the owner's right to sign in: suspending,
     * deactivating or deleting a user revokes every token they own, wherever
     * the change is made (admin screen, flow, tinker). ResolveApiToken also
     * refuses a token whose owner can't sign in, as a second line.
     */
    protected static function booted(): void
    {
        static::updated(static function (self $user): void {
            // Re-read so DB defaults (status) count, not just what was set in memory.
            if ($user->wasChanged(['is_active', 'status']) && ! ($user->fresh()?->canLogin() ?? false)) {
                $user->revokeApiTokens();
            }
        });

        static::deleted(static function (self $user): void {
            $user->revokeApiTokens();
        });
    }

    /** Delete every API token this user owns. */
    public function revokeApiTokens(): int
    {
        return PbApiToken::query()->where('pb_user_id', $this->getKey())->delete();
    }

    /** True when the account may sign in (active + not soft-deactivated). */
    public function canLogin(): bool
    {
        return $this->is_active && $this->getAttribute('status') === self::STATUS_ACTIVE;
    }

    /** True once the user has enrolled AND verified two-factor (so it gates login). */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->getAttribute('two_factor_confirmed_at') !== null;
    }

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
