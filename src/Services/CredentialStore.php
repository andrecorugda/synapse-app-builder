<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\Credential;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Resolves a stored {@see Credential} by key into the HTTP headers that
 * authenticate an outgoing request, so flow nodes reference secrets by key
 * instead of inlining them.
 *
 * Strictly best-effort: an unknown key, a missing table (fresh install), or a
 * secret that fails to decrypt all return an empty header set rather than
 * throwing — a broken credential degrades to "no auth", never a crashed flow.
 */
class CredentialStore
{
    /**
     * The HTTP headers to apply for the credential stored under $key, without
     * checking where they go. Outbound requests use {@see headersForHost()}.
     *
     * @return array<string,string>
     */
    public function headers(string $key): array
    {
        $credential = $this->find($key);

        if ($credential === null) {
            return [];
        }

        $secret = $credential->secret;

        if ($secret === null || $secret === '') {
            return [];
        }

        /** @var array<string,mixed> $meta */
        $meta = $credential->meta ?? [];

        return match ($credential->type) {
            'bearer' => ['Authorization' => 'Bearer '.$secret],
            'api_key' => [$this->apiKeyHeaderName($meta) => $secret],
            'basic' => ['Authorization' => 'Basic '.base64_encode($this->basicUsername($meta).':'.$secret)],
            default => [],
        };
    }

    /**
     * The headers for the credential under $key on a request to $host, or
     * null when the credential exists but may not be sent there (its allowed
     * hosts don't include $host, or the list is empty). An unknown key or an
     * unreadable secret gives [] as {@see headers()} does.
     *
     * @return array<string,string>|null
     */
    public function headersForHost(string $key, string $host): ?array
    {
        $credential = $this->find($key);

        if ($credential === null) {
            return [];
        }

        if (! $credential->allowsHost($host)) {
            return null;
        }

        return $this->headers($key);
    }

    private function find(string $key): ?Credential
    {
        if ($key === '') {
            return null;
        }

        try {
            /** @var class-string<Model> $model */
            $model = config('ai-page-builder.models.credential', Credential::class);

            /** @var Credential|null $credential */
            $credential = $model::query()->where('key', $key)->first();

            return $credential;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function apiKeyHeaderName(array $meta): string
    {
        $name = $meta['header_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'X-API-Key';
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function basicUsername(array $meta): string
    {
        $username = $meta['username'] ?? null;

        return is_string($username) ? $username : '';
    }
}
