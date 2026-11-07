<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Services\CredentialHosts;
use Andre\AiPageBuilder\Services\Settings;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * A reusable, ENCRYPTED secret an HTTP-request flow node can apply by key — so
 * flows call external APIs without inlining tokens in the flow definition.
 *
 * The `secret` column is always stored encrypted: the mutator encrypts on the
 * way in and the accessor decrypts on the way out, mirroring
 * {@see Settings::setEncrypted}. A decrypt that
 * fails (e.g. the app key rotated) yields null rather than throwing, so a
 * broken credential can never crash a flow run or the admin UI.
 *
 * @property int $id
 * @property string $name
 * @property string $key
 * @property string $type 'bearer'|'api_key'|'basic'
 * @property string|null $secret Plaintext via the accessor; encrypted at rest.
 * @property array<string,mixed>|null $meta {header_name?, username?}
 * @property list<string> $allowed_hosts Hosts the credential may be sent to
 *                                       (exact or `*.example.com`); empty = none.
 */
class Credential extends Model
{
    protected $guarded = [];

    /** Never serialise the decrypted secret (toArray / JSON / Filament fill). */
    protected $hidden = ['secret'];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * True when this credential may be attached to a request to $host. An
     * empty allow-list refuses every host (fail closed).
     */
    public function allowsHost(string $host): bool
    {
        return CredentialHosts::matches($host, $this->allowed_hosts);
    }

    /**
     * Hosts are stored as a JSON list, cleaned on the way in (lowercase, one
     * entry per host or `*.host` pattern, a pasted URL reduced to its host).
     */
    protected function allowedHosts(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                $decoded = $value === null || $value === '' ? [] : json_decode($value, true);

                return CredentialHosts::normalize(is_array($decoded) ? $decoded : []);
            },
            set: fn (mixed $value): ?string => ($hosts = CredentialHosts::normalize($value ?? [])) === []
                ? null
                : (string) json_encode($hosts),
        );
    }

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('credentials');
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * Transparently encrypt at rest / decrypt on read. A null/empty secret is
     * stored as-is; a decrypt failure returns null instead of throwing.
     */
    protected function secret(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (Throwable) {
                    return null;
                }
            },
            set: fn (?string $value): ?string => ($value === null || $value === '')
                ? null
                : Crypt::encryptString($value),
        );
    }
}
