<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Ai;

use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;

/**
 * Orchestrates AI app generation: assembles the request (the seeded `app_builder`
 * integration supplies the engine system prompt; this app's live context is sent
 * as a prompt arg; the owner's business guidelines + the user's brief are the
 * conversation body), invokes the AI OpenRouter Gateway, and parses the model's
 * reply into a validated Build Plan that BuildPlanApplier can apply.
 *
 * The gateway is REQUIRED for this feature (it carries the key, model, metering
 * and conversation threads) — guarded via the AiInvoker abstraction so the rest
 * of the package stays gateway-optional and this stays unit-testable with a fake.
 */
class AppBuilderService
{
    public function __construct(
        private readonly AiInvoker $invoker,
        private readonly AppContextBuilder $context,
        private readonly BuildPlanValidator $validator,
    ) {}

    public function available(): bool
    {
        return $this->invoker->available();
    }

    /**
     * Turn a natural-language brief into a validated Build Plan.
     *
     * @param  array<int,array<string,mixed>>  $messages  prior conversation turns (threads)
     * @return array{plan: array<string,mixed>, errors: array<int,string>, raw: string}
     */
    public function generate(string $brief, ?string $business = null, array $messages = []): array
    {
        if (! $this->available()) {
            throw new \RuntimeException('AI app generation requires the AI OpenRouter Gateway (andrecorugda/ai-openrouter-gateway).');
        }

        $slug = (string) config('ai-page-builder.ai.app_builder_slug', 'app_builder');

        $body = trim(
            ($business !== null && trim($business) !== '' ? "Business guidelines:\n".trim($business)."\n\n" : '')
            ."Request:\n".$brief
        );

        $conversation = array_merge($messages, [['role' => 'user', 'content' => $body]]);

        $raw = $this->invoker->invoke(
            $slug,
            ['app_context' => $this->context->toPromptString()],
            $conversation,
        );

        $plan = $this->extractPlan($raw);
        $errors = $this->validator->validate($plan);

        return ['plan' => $plan, 'errors' => $errors, 'raw' => $raw];
    }

    /**
     * Extract the JSON build plan from the model's reply — tolerant of code
     * fences or surrounding prose.
     *
     * @return array<string,mixed>
     */
    private function extractPlan(string $raw): array
    {
        $text = trim($raw);

        // A plan is carried ONLY in a fenced ```json block (so conversational
        // prose is never misread as a plan)…
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m) === 1) {
            $decoded = json_decode(trim($m[1]), true);

            return is_array($decoded) ? $decoded : [];
        }

        // …or the whole message is a JSON object (one-shot / programmatic use).
        if (str_starts_with($text, '{') && str_ends_with($text, '}')) {
            $decoded = json_decode($text, true);

            return is_array($decoded) ? $decoded : [];
        }

        // Otherwise it's a conversational reply — no plan.
        return [];
    }
}
