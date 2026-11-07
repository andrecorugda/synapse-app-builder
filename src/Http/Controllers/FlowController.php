<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Services\AccessControl;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class FlowController
{
    /**
     * Run a flow by slug and return its result actions as JSON.
     */
    public function run(Request $request, string $slug): JsonResponse
    {
        /** @var class-string<Flow> $model */
        $model = config('ai-page-builder.models.flow', Flow::class);

        // Any active flow is runnable here — how a flow was authored to be
        // triggered (manual/component/…) does not gate access; the Public flag does:
        //   • Public  → callable by anyone, from ANY origin (external / webhook / API).
        //   • Private → callable only by a signed-in app user (pb session) whose
        //     role may `run` the flow, from the app's own pages (same origin).
        // A refused hit on a private flow is 404 (not 403) so the endpoint never
        // leaks that the flow exists.
        /** @var Flow|null $flow */
        $flow = $model::query()
            ->active()
            ->where('slug', $slug)
            ->first();

        if ($flow === null) {
            abort(404);
        }

        if (! $flow->is_public && ! $this->mayRunPrivate($request, $flow)) {
            abort(404);
        }

        $maxAttempts = $flow->rate_limit_per_minute ?? (int) config('ai-page-builder.flow.rate_limit_per_minute', 30);
        $rateLimitKey = 'pb-flow:'.$slug.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return response()->json(['error' => 'Too many requests'], 429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        /** @var array<string,mixed> $input */
        $input = (array) $request->input('input', []);

        try {
            // This is the page/API trigger path: the request payload IS the page's
            // live $store.app state, so overlay it onto `states.*` (so a node's
            // {{ states.email }} resolves to what the visitor typed, not the empty
            // persisted Variable). Only client-visible States and page-local keys
            // may be overlaid — a server-only State keeps its persisted value
            // whatever the caller sends. Non-page triggers (cron/collection/admin)
            // pass none.
            $ctx = app(FlowManager::class)->run($flow, $input, app(VariableStore::class)->clientOverlay($input));

            return response()->json(['actions' => $ctx->actions]);
        } catch (\Throwable $e) {
            Log::warning('[ai-page-builder] flow run failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Flow failed'], 500);
        }
    }

    /**
     * A private flow runs only for a signed-in app user whose role may `run` it
     * (permission rows with resource_type `flow`; a flow nobody has a row for is
     * open to every signed-in user, an admin role passes always). The Origin /
     * Referer check is CSRF protection for that session cookie only — it is
     * trivially forged outside a browser, so it never grants access on its own.
     * With app auth disabled there is no signed-in user, so private flows are
     * not reachable over HTTP at all.
     */
    private function mayRunPrivate(Request $request, Flow $flow): bool
    {
        if (! (bool) config('ai-page-builder.auth.enabled', true) || ! $this->isSameOrigin($request)) {
            return false;
        }

        $access = app(AccessControl::class);
        $user = $access->currentUser();

        return $user !== null && $access->can($user, 'run', 'flow', (string) $flow->slug);
    }

    /**
     * True when the request originates from the app's own host (Origin, falling
     * back to Referer). A request with neither header is treated as cross-origin.
     */
    private function isSameOrigin(Request $request): bool
    {
        foreach (['Origin', 'Referer'] as $header) {
            $value = $request->headers->get($header);
            if (is_string($value) && $value !== '') {
                $host = parse_url($value, PHP_URL_HOST);

                return is_string($host) && $host === $request->getHost();
            }
        }

        return false;
    }
}
