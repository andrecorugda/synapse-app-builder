<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A long-lived API token for the collections REST API. The token is presented
 * as a Bearer credential; only its sha256 hash is stored, so the plaintext is
 * shown once at creation and never recoverable.
 *
 * A token may belong to a {@see PbUser} (the end-user it acts as, so the
 * REST API's AccessControl scopes reads/writes to that user's permissions and
 * row-level rules) or be ownerless — an ownerless token has full access, the
 * same as an unauthenticated caller on an unrestricted collection.
 *
 * @property int $id
 * @property int|null $pb_user_id
 * @property string $name
 * @property string $token sha256 hash of the plaintext
 * @property array<int,string>|null $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 */
class PbApiToken extends Model
{
    /** Bytes of randomness behind a plaintext token (40 hex chars). */
    private const TOKEN_BYTES = 20;

    protected $guarded = [];

    protected $hidden = ['token'];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return self::tableName();
    }

    /**
     * The physical table name. Not in the configurable tables map (the package
     * registers this migration by bare name), so it is fixed here and shared by
     * the migration.
     */
    public static function tableName(): string
    {
        return 'page_builder_api_tokens';
    }

    /**
     * @return BelongsTo<PbUser, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<PbUser> $userClass */
        $userClass = config('ai-page-builder.models.user', PbUser::class);

        return $this->belongsTo($userClass, 'pb_user_id');
    }

    /**
     * Mint a new token: persist its hash and return the model together with the
     * one-time plaintext (caller must surface it immediately — it is not stored).
     *
     * @param  array<int,string>|null  $abilities
     * @return array{token: self, plain_text: string}
     */
    public static function generate(
        string $name,
        ?int $pbUserId = null,
        ?array $abilities = null,
        ?Carbon $expiresAt = null,
    ): array {
        $plain = self::newPlainTextToken();

        /** @var self $token */
        $token = static::query()->create([
            'pb_user_id' => $pbUserId,
            'name' => $name,
            'token' => self::hash($plain),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plain_text' => $plain];
    }

    /**
     * Find a token by its plaintext, hashing it first. Returns null when the
     * token is unknown or has expired.
     */
    public static function findToken(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        /** @var self|null $token */
        $token = static::query()->where('token', self::hash($plain))->first();

        if ($token === null || $token->hasExpired()) {
            return null;
        }

        return $token;
    }

    /** True when the token carries an expiry that is now in the past. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Record that the token was just used (throttled to once per minute). */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /** A fresh, unguessable plaintext token. */
    public static function newPlainTextToken(): string
    {
        return Str::random(8).bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /** The stored, comparable form of a plaintext token. */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
