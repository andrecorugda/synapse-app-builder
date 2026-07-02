<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Models\Flow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class FlowController
{
    /**
     * Run a public flow by slug and return its result actions as JSON.
     */
    public function run(Request $request, string $slug): JsonResponse
    {
        /** @var class-string<Flow> $model */
        $model = config('ai-page-builder.models.flow', Flow::class);

        // Any active flow is runnable here — how a flow was authored to be
        // triggered (manual/component/…) does not gate access; the Public flag does:
        //   • Public  → callable from ANY origin (external / webhook / API).
        //   • Private → callable only SAME-ORIGIN (the app's own pages).
        // A cross-origin hit on a private flow is 404 (not 403) so the endpoint
        // never leaks that the flow exists.
        /** @var Flow|null $flow */
        $flow = $model::query()
            ->active()
            ->where('slug', $slug)
            ->first();

        if ($flow === null) {
            abort(404);
        }

        if (! $flow->is_public && ! $this->isSameOrigin($request)) {
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
            // persisted Variable). Non-page triggers (cron/collection/admin) pass none.
            $ctx = app(FlowManager::class)->run($flow, $input, $input);

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
