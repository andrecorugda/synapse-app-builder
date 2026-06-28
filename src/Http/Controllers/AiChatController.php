<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Ai\AppBuilderService;
use Andre\AiPageBuilder\Ai\BuildPlan;
use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Backs the dockable AI chat in the admin panel. Each turn threads the whole
 * conversation through AppBuilderService (so the model retains context and can
 * iteratively REFINE — the applier is idempotent, so a plan referencing
 * existing slugs updates them). Generation is decoupled from application:
 * `send` proposes a plan, `apply` commits it (human-in-the-loop).
 */
class AiChatController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $service = app(AppBuilderService::class);

        if (! $service->available()) {
            return response()->json([
                'available' => false,
                'reply' => 'AI generation needs the AI OpenRouter Gateway (andrecorugda/ai-openrouter-gateway). You can still build manually.',
                'plan' => [],
                'errors' => [],
            ]);
        }

        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string'],
            'messages.*.content' => ['required', 'string'],
        ]);

        /** @var list<array{role:string,content:string}> $messages */
        $messages = array_map(
            static fn (array $m): array => [
                'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $m['content'],
            ],
            $data['messages'],
        );

        $last = end($messages);
        $brief = is_array($last) ? (string) $last['content'] : '';
        $history = array_slice($messages, 0, -1);

        try {
            $result = $service->generate($brief, null, $history);
        } catch (Throwable $e) {
            return response()->json(['available' => true, 'reply' => 'Sorry — '.$e->getMessage(), 'plan' => [], 'errors' => []]);
        }

        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        $raw = (string) ($result['raw'] ?? '');
        $errors = array_values(array_filter((array) ($result['errors'] ?? []), 'is_string'));

        return response()->json([
            'available' => true,
            // Friendly text to show; raw kept for conversation continuity.
            'reply' => $plan === [] ? $raw : $this->summarize($plan),
            'raw' => $raw,
            'plan' => $plan,
            'errors' => $errors,
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $plan = $request->input('plan');
        if (! is_array($plan) || $plan === []) {
            return response()->json(['message' => 'Nothing to apply.'], 422);
        }

        try {
            /** @var array<string,mixed> $plan */
            $result = app(BuildPlanApplier::class)->apply($plan);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json($result);
    }

    /**
     * A one-line, human summary of a proposed plan ("2 collections, 1 page…").
     *
     * @param  array<string,mixed>  $plan
     */
    private function summarize(array $plan): string
    {
        $build = BuildPlan::fromArray($plan);
        $parts = [];

        $sections = [
            'collection' => count($build->collections()),
            'state' => count($build->states()),
            'function' => count($build->functions()),
            'flow' => count($build->flows()),
            'page' => count($build->pages()),
        ];
        foreach ($sections as $label => $n) {
            if ($n > 0) {
                $parts[] = $n.' '.($n === 1 ? $label : $label.'s');
            }
        }
        if ($build->settings() !== []) {
            $parts[] = 'settings';
        }

        return $parts === []
            ? 'No changes proposed.'
            : 'Proposed: '.implode(', ', $parts).'. Review and apply below.';
    }
}
