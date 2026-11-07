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
use Andre\AiPageBuilder\Services\CredentialHosts;
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
                new CapabilityInput('credential', 'Credential', 'string', help: 'Optional stored credential key; its auth headers are merged in and take precedence over inline headers. The URL\'s host must be one of the credential\'s allowed hosts, or the request is not made.'),
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

        // Laravel's HTTP client expands RFC 6570 URI templates before sending:
        // an unknown `{…}` expression is deleted, so the host that is dialled
        // can differ from the one every check below reads with parse_url()
        // (`https://evil.example{x@api.allowed.com/y}` checks as
        // api.allowed.com, connects to evil.example). Braces are never valid in
        // a URL we send, so refuse them outright.
        $pin = $url === '' || self::hasTemplateSyntax($url) ? false : $this->vetUrl($url);

        if ($pin === false) {
            if ($url !== '') {
                Log::warning('[ai-page-builder] http node blocked a request to a disallowed/internal URL.');
            }
            $context->set($output, null);
            $context->set($output.'_status', 0);

            return (array) ($node['next'] ?? []);
        }

        // Attach the credential only AFTER the URL passed the SSRF guard, so a
        // crafted internal URL can never receive a decrypted secret — and only
        // to a host on the credential's own allow-list (kept by whoever may
        // manage secrets), so a flow builder can't send it to a host they own.
        // A request the credential may not go to is not made at all.
        $credentialKey = trim((string) ($config['credential'] ?? ''));
        if ($credentialKey !== '') {
            $credentialHeaders = app(CredentialStore::class)->headersForHost($credentialKey, CredentialHosts::hostOf($url));
            if ($credentialHeaders === null) {
                Log::warning('[ai-page-builder] http node refused to send a credential to a host outside its allowed hosts.', [
                    'credential' => $credentialKey,
                    'host' => CredentialHosts::hostOf($url),
                ]);
                $context->set($output, null);
                $context->set($output.'_status', 0);

                return (array) ($node['next'] ?? []);
            }
            $headers = array_merge($headers, $credentialHeaders);
        }

        $request = Http::withHeaders($headers);

        // Pin the connection to the addresses that were just vetted, so a second
        // DNS answer (DNS rebinding) can't swap in an internal one between the
        // check and the connect.
        if ($pin !== [] && defined('CURLOPT_RESOLVE')) {
            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => $pin]]);
        }

        $response = $request
            ->withoutRedirecting() // don't let a public host 302 into an internal one
            ->timeout(max(1, (int) config('ai-page-builder.flow.http_timeout', 15))) // bound a slow host tying up a worker
            ->{$method}($url, $body);

        $context->set($output, $response->json() ?? $response->body());
        $context->set($output.'_status', $response->status());

        return (array) ($node['next'] ?? []);
    }

    /**
     * SSRF guard. Returns false when the URL must not be called: a non-http(s)
     * scheme, a host off the optional allow-list, a host that does not resolve,
     * or one that resolves to ANY private / reserved / loopback / link-local /
     * shared (100.64.0.0/10) / NAT64 / IPv4-mapped address. Otherwise returns
     * the CURLOPT_RESOLVE pin (`host:port:addr,…`) for the vetted addresses —
     * empty when the host is an IP literal or the guard is off.
     *
     * @return list<string>|false
     */
    /** True when the URL holds `{` or `}` — the HTTP client would expand them as a URI template. */
    public static function hasTemplateSyntax(string $url): bool
    {
        return strpbrk($url, '{}') !== false;
    }

    private function vetUrl(string $url): array|false
    {
        if (! (bool) config('ai-page-builder.flow.http_block_private_hosts', true)) {
            return [];
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        // Normalize the host to the form the transport actually dials: strip the
        // brackets parse_url leaves on an IPv6 literal (`[::1]` → `::1`), and
        // expand a dotless/decimal IPv4 (`2130706433` → `127.0.0.1`) — cURL
        // accepts these numeric spellings, so the raw string alone would slip
        // past the IP check.
        $rawHost = $host;
        $host = $this->normalizeHost($host);

        $allowed = array_map('strtolower', (array) config('ai-page-builder.flow.http_allowed_hosts', []));
        if ($allowed !== [] && ! in_array($host, $allowed, true)) {
            return false;
        }

        // `localhost` (and any *.localhost) never leaves the box; dns_get_record
        // ignores /etc/hosts so it would otherwise resolve to nothing and slip
        // through.
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isInternalIp($host) ? false : [];
        }

        // Resolve the host; reject if ANY resolved address is internal.
        $ips = $this->resolveHost($host);

        // A name that resolves to nothing here is refused: otherwise the
        // transport would resolve it again on its own, and a rebinding DNS
        // server could answer the second lookup with an internal address.
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if ($this->isInternalIp($ip)) {
                return false;
            }
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = implode(',', array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
            $ips,
        ));

        return [$rawHost.':'.$port.':'.$addresses];
    }

    /**
     * Every address a host name resolves to. gethostbynamel() uses the libc
     * resolver (consults /etc/hosts, matching what cURL dials, so a name mapped
     * to loopback there is caught); dns_get_record() adds AAAA.
     *
     * @return list<string>
     */
    protected function resolveHost(string $host): array
    {
        $ips = [];
        foreach ((array) (@gethostbynamel($host) ?: []) as $ip) {
            $ips[] = (string) $ip;
        }
        foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $rec) {
            $ips[] = (string) ($rec['ip'] ?? $rec['ipv6'] ?? '');
        }

        return array_values(array_unique(array_filter($ips)));
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

    /**
     * Ranges PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE don't cover (on every
     * supported PHP version) but that are not the public internet, or reach
     * internal hosts: shared/benchmark/protocol space, documentation and
     * multicast ranges, and IPv6 forms that embed an IPv4 address.
     */
    private const EXTRA_BLOCKED = [
        '0.0.0.0/8',       // "this network"; 0.0.0.0 reaches the local host
        '100.64.0.0/10',   // shared address space (RFC 6598), cloud-internal on several providers
        '192.0.0.0/24',    // IETF protocol assignments (RFC 6890)
        '192.0.2.0/24',    // TEST-NET-1
        '198.18.0.0/15',   // benchmarking (RFC 2544), used inside some networks
        '198.51.100.0/24', // TEST-NET-2
        '203.0.113.0/24',  // TEST-NET-3
        '224.0.0.0/4',     // multicast
        '240.0.0.0/4',     // reserved, incl. 255.255.255.255 broadcast
        '64:ff9b::/96',    // NAT64 well-known prefix → maps onto any IPv4, incl. 127.0.0.1
        '64:ff9b:1::/48',  // local-use NAT64 (RFC 8215)
        '::ffff:0:0/96',   // IPv4-mapped IPv6
        '::/96',           // IPv4-compatible IPv6 (deprecated)
        '100::/64',        // discard-only
        '2001::/32',       // Teredo: embeds an (obfuscated) IPv4 address
        '2001:db8::/32',   // documentation
        'fc00::/7',        // unique local
        'fec0::/10',       // site-local (deprecated, still routed internally on some networks)
        'fe80::/10',       // link-local
        'ff00::/8',        // multicast
    ];

    /**
     * True for private / reserved / loopback / link-local (incl. 169.254.169.254)
     * and EXTRA_BLOCKED addresses. A 6to4 address (2002::/16) is judged by the
     * IPv4 address it embeds, so 2002:7f00:1:: (→ 127.0.0.1) is internal.
     */
    private function isInternalIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        foreach (self::EXTRA_BLOCKED as $cidr) {
            if ($this->inCidr($ip, $cidr)) {
                return true;
            }
        }

        if ($this->inCidr($ip, '2002::/16')) {
            $bin = (string) inet_pton($ip);
            $embedded = inet_ntop(substr($bin, 2, 4));

            return $embedded === false || $this->isInternalIp($embedded);
        }

        return false;
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
