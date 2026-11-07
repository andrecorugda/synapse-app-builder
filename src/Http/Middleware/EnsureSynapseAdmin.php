<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Middleware;

use Andre\AiPageBuilder\Support\SynapseAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the in-panel HTTP endpoints (AI chat, media upload, PHP lint).
 *
 * Being signed in on the host's default guard is NOT enough: the user must be
 * a Filament user allowed into a panel that runs Synapse
 * (FilamentUser::canAccessPanel()) AND pass the `synapse.admin` ability (or
 * the plugin's ->authorize() callback). Fails closed — no Filament, no panel,
 * no user, or no grant all answer 403.
 *
 * Usage: `EnsureSynapseAdmin::class` (admin scope) or
 * `EnsureSynapseAdmin::class.':manage-code'` for a finer scope.
 */
class EnsureSynapseAdmin
{
    public function handle(Request $request, Closure $next, string $scope = SynapseAccess::ADMIN): Response
    {
        abort_unless(SynapseAccess::allowsPanelRoute($scope), 403);

        return $next($request);
    }
}
