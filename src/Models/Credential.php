<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

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
 */
class Credential extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

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
