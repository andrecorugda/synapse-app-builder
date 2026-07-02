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

        // Runnable from this endpoint when the flow is either explicitly Public
        // (unauthenticated webhook-style trigger) OR wired to a page component
        // (trigger_type=component) — a component trigger IS the page invoking it,
        // so requiring the separate "Public" toggle too was a silent 404 trap.
        /** @var Flow|null $flow */
        $flow = $model::query()
            ->active()
            ->where('slug', $slug)
            ->where(function ($q): void {
                $q->where('is_public', true)->orWhere('trigger_type', 'component');
            })
            ->first();

        if ($flow === null) {
            abort(404);
        }

        // A non-public (component-triggered) flow is runnable ONLY from the app's
        // own pages — require same-origin so it can't be triggered cross-site. Be
        // 404 (not 403) when the origin doesn't match, so the endpoint never leaks
        // that a private flow exists. Public flows (webhook-style) skip this.
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
            $ctx = app(FlowManager::class)->run($flow, $input);

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
