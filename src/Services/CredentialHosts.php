<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Flow\Nodes\HttpRequestNode;
use Andre\AiPageBuilder\Models\Credential;
use Andre\AiPageBuilder\Models\Flow;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The hosts a stored {@see Credential} may be sent to.
 *
 * Whoever may build flows may point an HTTP Request node anywhere; without a
 * host list a builder who is NOT allowed to see secrets could still have one
 * sent to a server they control. So a credential carries its own allow-list,
 * kept on the Credentials screen (manage-secrets), and its headers go only to
 * a matching host. An empty list sends the credential nowhere.
 *
 * A pattern is an exact host (`api.stripe.com`) or a wildcard for subdomains
 * (`*.example.com` matches `a.example.com` and `a.b.example.com`, not
 * `example.com` itself). Matching ignores case, a trailing dot and the port.
 */
class CredentialHosts
{
    /** One host label, letters/digits/hyphens. */
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * Clean a list typed by an admin (array, or comma / whitespace separated):
     * lowercase, trim, accept a pasted URL by keeping its host, drop duplicates.
     * Entries that are not a host or `*.host` pattern are dropped.
     *
     * @return list<string>
     */
    public static function normalize(mixed $hosts): array
    {
        $items = is_array($hosts) ? $hosts : preg_split('/[\s,]+/', (string) $hosts, -1, PREG_SPLIT_NO_EMPTY);

        $out = [];
        foreach ((array) $items as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $host = self::pattern((string) $item);
            if ($host !== null) {
                $out[] = $host;
            }
        }

        return array_values(array_unique($out));
    }

    /** True when the entry is a valid host or `*.host` pattern (after cleaning). */
    public static function isValidPattern(string $entry): bool
    {
        return self::pattern($entry) !== null;
    }

    /**
     * @param  list<string>  $patterns
     */
    public static function matches(string $host, array $patterns): bool
    {
        $host = self::canonicalHost($host);
        if ($host === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, '*.')) {
                if (str_ends_with($host, substr($pattern, 1))) {
                    return true;
                }

                continue;
            }

            if ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    /** The host of a URL as compared against the list ('' when it has none). */
    public static function hostOf(string $url): string
    {
        // A URI-template brace would be expanded by the HTTP client before it
        // dials, so the parsed host is not the host contacted: match nothing.
        if (HttpRequestNode::hasTemplateSyntax($url)) {
            return '';
        }

        return self::canonicalHost((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    }

    /**
     * For every credential key referenced by an HTTP Request node in a stored
     * flow (nested loop / transaction bodies included), the hosts those nodes
     * call. A URL whose host is built from a `{{ token }}` is skipped: only a
     * host the flow author typed is a host they chose.
     *
     * @return array<string,list<string>> credential key => hosts
     */
    public function hostsUsedByFlows(): array
    {
        try {
            /** @var class-string<Model> $model */
            $model = config('ai-page-builder.models.flow', Flow::class);
            $definitions = $model::query()->pluck('definition')->all();
        } catch (Throwable) {
            return [];
        }

        $found = [];
        foreach ($definitions as $definition) {
            if (is_string($definition)) {
                $definition = json_decode($definition, true);
            }
            if (is_array($definition)) {
                $this->collect($definition, $found);
            }
        }

        return array_map(static fn (array $hosts): array => array_values(array_unique($hosts)), $found);
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string,list<string>>  $found
     */
    private function collect(array $node, array &$found): void
    {
        if (($node['type'] ?? null) === 'http_request' && is_array($node['config'] ?? null)) {
            $key = trim((string) ($node['config']['credential'] ?? ''));
            $url = (string) ($node['config']['url'] ?? '');
            $host = self::hostOf($url);

            if ($key !== '' && $host !== '' && ! str_contains($host, '{') && self::isValidPattern($host)) {
                $found[$key][] = $host;
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collect($child, $found);
            }
        }
    }

    private static function pattern(string $entry): ?string
    {
        $entry = strtolower(trim($entry));
        if ($entry === '') {
            return null;
        }

        // A pasted URL: keep its host.
        if (str_contains($entry, '://')) {
            $entry = (string) (parse_url($entry, PHP_URL_HOST) ?? '');
        }

        $entry = rtrim($entry, '.');
        $wildcard = str_starts_with($entry, '*.');
        $host = $wildcard ? substr($entry, 2) : $entry;

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return $wildcard ? null : trim($host, '[]');
        }

        $label = self::LABEL;
        // At least two labels for a wildcard, so `*.com` can't allow a whole TLD.
        $regex = $wildcard ? "/^{$label}(\\.{$label})+$/" : "/^{$label}(\\.{$label})*$/";

        return preg_match($regex, $host) === 1 ? ($wildcard ? '*.'.$host : $host) : null;
    }

    private static function canonicalHost(string $host): string
    {
        return rtrim(trim(strtolower($host), '[]'), '.');
    }
}
