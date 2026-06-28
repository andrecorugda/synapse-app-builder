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

        /** @var Flow|null $flow */
        $flow = $model::query()
            ->active()
            ->where('slug', $slug)
            ->where('is_public', true)
            ->first();

        if ($flow === null) {
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
}
