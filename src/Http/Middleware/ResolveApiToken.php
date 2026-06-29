<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Middleware;

use Andre\AiPageBuilder\Models\PbApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a Bearer API token on the collections REST API and authenticates its
 * owner on the `pb` guard for the request — so RecordApiController + AccessControl
 * apply the owner's permissions and row-level rules without any change.
 *
 * Behaviour:
 *   - No Bearer token → pass through untouched (same-origin session auth, and
 *     unrestricted-collection public access, both keep working).
 *   - Valid, unexpired token → set its PbUser on the guard (ownerless token =
 *     no user set = full access) and bump last_used_at.
 *   - Bearer present but unknown/expired → 401 JSON (an explicit credential that
 *     fails must not silently fall through to public access).
 */
class ResolveApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        // No token: leave the request as-is. Session auth / public collections
        // are unaffected.
        if ($bearer === null || $bearer === '') {
            return $next($request);
        }

        $token = PbApiToken::findToken($bearer);

        if ($token === null) {
            return new JsonResponse(['message' => 'Invalid API token.'], 401);
        }

        $token->touchLastUsed();

        // Ownerless token = full access: don't set a user (AccessControl then
        // treats the caller like an unauthenticated client on open collections,
        // and a restricted collection still rejects writes that need a user).
        if ($token->pb_user_id !== null && ($user = $token->user) !== null) {
            Auth::guard((string) config('ai-page-builder.auth.guard', 'pb'))->setUser($user);
        }

        return $next($request);
    }
}
