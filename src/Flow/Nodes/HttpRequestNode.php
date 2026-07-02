<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Models\Credential;
use Andre\AiPageBuilder\Services\CredentialStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls an external HTTP endpoint and stores the JSON/body in a var.
 * config: { method, url, headers:{...}, body:{...}, credential:"key", output:"varName" }
 *
 * `credential` references a stored {@see Credential} by key; its auth headers
 * are merged in (taking precedence over inline `headers`) so secrets stay out of
 * the flow definition.
 *
 * SSRF guard: the resolved URL is validated before the request — only http/https
 * to non-internal hosts is allowed (private/reserved/loopback/link-local IPs incl.
 * the cloud metadata endpoint are refused), the HTTP verb is whitelisted, and
 * redirects are not followed. This matters because a public flow can be triggered
 * unauthenticated and may carry a stored credential. See config `flow.http_*`.
 */
class HttpRequestNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    /** @var array<int,string> */
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head'];

    public function type(): string
    {
        return 'http_request';
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'HTTP Request',
            category: CapabilityCategory::Integrations,
            description: 'Calls an external HTTP endpoint and stores the parsed JSON (or raw body) plus the status code in context variables. Internal/private hosts are blocked (SSRF guard); a stored credential can supply auth headers without exposing secrets in the flow.',
            usage: 'method "post", url "https://api.example.com/orders", body {id: "{{ input.id }}"}, output "resp" → exposes {{ vars.resp }} and {{ vars.resp_status }}.',
            icon: 'globe-alt',
            inputs: [
                new CapabilityInput('method', 'Method', 'select', default: 'get', options: [
                    'get' => 'GET',
                    'post' => 'POST',
                    'put' => 'PUT',
                    'patch' => 'PATCH',
                    'delete' => 'DELETE',
                    'head' => 'HEAD',
                ]),
                new CapabilityInput('url', 'URL', 'string', required: true, help: 'Full http(s) URL to call (interpolated). Private/internal hosts are rejected.'),
                new CapabilityInput('headers', 'Headers', 'keyvalue', help: 'Request headers as key/value pairs (interpolated).'),
                new CapabilityInput('body', 'Body', 'json', help: 'Request body for write verbs (interpolated).'),
                new CapabilityInput('credential', 'Credential', 'string', help: 'Optional stored credential key; its auth headers are merged in and take precedence over inline headers.'),
                new CapabilityInput('output', 'Output variable', 'string', default: 'http', help: 'Context variable for the response body; the status is written to <output>_status (default "http").'),
            ],
            outputHandles: ['next'],
        );
    }

    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);
        $method = strtolower((string) ($config['method'] ?? 'get'));
        if (! in_array($method, self::METHODS, true)) {
            $method = 'get';
        }
        $url = $context->interpolate((string) ($config['url'] ?? ''));
        /** @var array<string,mixed> $headers */
        $headers = $context->interpolateDeep((array) ($config['headers'] ?? []));
        /** @var array<string,mixed> $body */
        $body = $context->interpolateDeep((array) ($config['body'] ?? []));
        $output = (string) ($config['output'] ?? 'http');

        if ($url === '' || $this->urlIsBlocked($url)) {
            if ($url !== '') {
                Log::warning('[ai-page-builder] http node blocked a request to a disallowed/internal URL.');
            }
            $context->set($output, null);
            $context->set($output.'_status', 0);

            return (array) ($node['next'] ?? []);
        }

        // Attach the credential only AFTER the URL passed the SSRF guard, so a
        // crafted internal URL can never receive a decrypted secret.
        $credentialKey = trim((string) ($config['credential'] ?? ''));
        if ($credentialKey !== '') {
            $headers = array_merge($headers, app(CredentialStore::class)->headers($credentialKey));
        }

        $response = Http::withHeaders($headers)
            ->withoutRedirecting() // don't let a public host 302 into an internal one
            ->{$method}($url, $body);

        $context->set($output, $response->json() ?? $response->body());
        $context->set($output.'_status', $response->status());

        return (array) ($node['next'] ?? []);
    }

    /**
     * SSRF guard: reject non-http(s) schemes, hosts off an optional allow-list,
     * and any host that resolves to a private/reserved/loopback/link-local IP.
     */
    private function urlIsBlocked(string $url): bool
    {
        if (! (bool) config('ai-page-builder.flow.http_block_private_hosts', true)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return true;
        }

        // Normalize the host to the form the transport actually dials: strip the
        // brackets parse_url leaves on an IPv6 literal (`[::1]` → `::1`), and
        // expand a dotless/decimal IPv4 (`2130706433` → `127.0.0.1`) — cURL
        // accepts these numeric spellings, so the raw string alone would slip
        // past the IP check.
        $host = $this->normalizeHost($host);

        $allowed = array_map('strtolower', (array) config('ai-page-builder.flow.http_allowed_hosts', []));
        if ($allowed !== [] && ! in_array($host, $allowed, true)) {
            return true;
        }

        // `localhost` (and any *.localhost) never leaves the box; dns_get_record
        // ignores /etc/hosts so it would otherwise resolve to nothing and slip
        // through.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Resolve the host; reject if ANY resolved address is internal.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            // gethostbynamel() uses the libc resolver (consults /etc/hosts,
            // matching what cURL dials); dns_get_record() adds AAAA. A name that
            // resolves to NOTHING is refused rather than dialed blind (fail closed).
            foreach ((array) (@gethostbynamel($host) ?: []) as $ip) {
                $ips[] = $ip;
            }
            foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $rec) {
                $ips[] = $rec['ip'] ?? $rec['ipv6'] ?? null;
            }

            if (array_filter($ips) === []) {
                return true;
            }
        }

        foreach (array_filter($ips) as $ip) {
            if ($this->isInternalIp((string) $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite a host into the canonical form the HTTP transport will dial, so
     * the IP checks below see the real address. Handles the two spellings that
     * bypass a naive check: bracketed IPv6 literals and dotless/decimal IPv4.
     */
    private function normalizeHost(string $host): string
    {
        // IPv6 literal: parse_url keeps the surrounding brackets.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        // Dotless / decimal IPv4 (e.g. 2130706433 == 127.0.0.1): an all-digit
        // host in the valid 32-bit range expands to dotted-quad.
        if (ctype_digit($host)) {
            $long = (int) $host;
            if ($long >= 0 && $long <= 4294967295) {
                return long2ip($long);
            }
        }

        return $host;
    }

    /** True for private / reserved / loopback / link-local (incl. 169.254.169.254). */
    private function isInternalIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
