<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection for the cookie-authenticated data REST API.
 *
 * The data API is stateless-friendly: it carries no Laravel CSRF token (so
 * Bearer-token clients and same-origin page fetches work without one). That
 * leaves session-cookie writes exposed to cross-site request forgery — a
 * malicious page could POST to a built app the victim is logged into, and the
 * browser would attach the `pb` session cookie automatically.
 *
 * This middleware closes that gap with an origin check, scoped to exactly the
 * requests that need it:
 *   - GET/HEAD/OPTIONS (read-only / preflight) always pass.
 *   - A request carrying an `Authorization: Bearer` token passes — browsers
 *     never auto-attach bearer tokens cross-site, so server-to-server and
 *     programmatic API usage is a non-CSRF vector and stays unaffected.
 *   - Any other write (POST/PUT/PATCH/DELETE) is cookie-authenticated: its
 *     `Origin` (or, lacking one, `Referer`) host MUST equal the app host.
 *     Same-origin published-page fetches pass; cross-origin forgeries 403.
 *
 * A cookie write with NO Origin and NO Referer is rejected — a same-origin
 * browser fetch always sends at least one of them, so the only callers missing
 * both are non-browser clients, which should authenticate with a Bearer token.
 */
class EnsureDataApiSameOrigin
{
    /** HTTP methods that mutate state and therefore need the origin check. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        // Read-only / preflight requests are never CSRF-sensitive.
        if (! in_array($request->getMethod(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        // Bearer-authenticated writes are not a CSRF vector (the browser cannot
        // forge the header cross-site) — let programmatic clients through.
        $bearer = $request->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            return $next($request);
        }

        // Cookie-authenticated write: the originating host must match the app.
        $origin = $this->originHost($request);
        if ($origin !== null && $origin === $request->getHost()) {
            return $next($request);
        }

        return new JsonResponse(['message' => 'Cross-origin request blocked.'], 403);
    }

    /**
     * The host the request originated from, preferring the `Origin` header and
     * falling back to `Referer`. Null when neither is present or parseable.
     */
    private function originHost(Request $request): ?string
    {
        foreach (['Origin', 'Referer'] as $header) {
            $value = $request->headers->get($header);
            if ($value === null || $value === '') {
                continue;
            }

            $host = parse_url($value, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return null;
    }
}
