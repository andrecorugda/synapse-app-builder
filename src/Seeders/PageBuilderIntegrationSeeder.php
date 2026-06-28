<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Seeders;

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Illuminate\Support\Facades\Schema as DbSchema;

/**
 * Auto-seeds a pre-configured `page_builder` integration into the AI OpenRouter
 * Gateway when it is installed, so AI page generation is metered, versioned and
 * cost-capped out of the box. Idempotent: never clobbers an admin-tuned prompt.
 *
 * The gateway is an OPTIONAL dependency, so its classes are referenced as
 * string literals (never imported / ::class) — nothing here autoloads or
 * type-resolves against the gateway, so the package is safe without it.
 */
class PageBuilderIntegrationSeeder
{
    public const FACADE = 'Andre\\AiGateway\\Facades\\AiGateway';

    public const MODEL = 'Andre\\AiGateway\\Models\\AiIntegration';

    public const SERVICE = 'Andre\\AiGateway\\Services\\AiIntegrationService';

    public static function gatewayInstalled(): bool
    {
        return class_exists(self::MODEL) && class_exists(self::SERVICE);
    }

    /**
     * @return bool true when a new integration was created this call
     */
    public function run(): bool
    {
        if (! self::gatewayInstalled()) {
            return false;
        }

        /** @var class-string $model */
        $model = self::MODEL;

        // The gateway may not be migrated yet (fresh install / pre-migrate boot).
        $probe = new $model;
        if (! DbSchema::connection($probe->getConnectionName())->hasTable($probe->getTable())) {
            return false;
        }

        $slug = (string) config('ai-page-builder.ai.gateway_slug', 'page_builder');

        $integration = $model::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => 'Page Builder',
                'description' => 'AI landing-page generation for the AI Page Builder plugin. Tune the prompt, model and cost caps here.',
                'provider' => 'openrouter',
                'visibility' => 'internal',
                'is_active' => true,
            ],
        );

        if (! $integration->wasRecentlyCreated) {
            return false;
        }

        app(self::SERVICE)->saveVersion(
            $integration,
            [
                'system_prompt' => self::systemPrompt(),
                'system_prompt_cacheable' => true,
                'models' => [(string) config('ai-page-builder.ai.default_model', 'anthropic/claude-sonnet-4')],
                'default_params' => ['temperature' => 0.4, 'max_tokens' => 4000],
                'prompt_args' => self::promptArgs(),
                'notes' => 'v1: seeded by andrecorugda/ai-page-builder',
            ],
            true,
            null,
        );

        return true;
    }

    private static function systemPrompt(): string
    {
        $sections = implode(', ', BlockVocabulary::keys());

        return <<<PROMPT
        You generate landing-page HTML for a GrapesJS editor. Output ONLY raw HTML
        (optionally a single leading <style> block) — no markdown, no code fences,
        no commentary.

        Use ONLY these section types: {$sections}. Wrap each section in
        <section data-pb-block="KEY">…</section> where KEY is one of the allowed
        types. Keep inner class names in the pb-KEY__* convention. Do not emit
        <script>, <iframe>, <html>, <head> or <body> tags.

        When given {{brief}}, produce a complete, sensibly-ordered page. When given
        {{section_type}} and {{instruction}}, produce ONLY that one section. When
        given {{current_html}} and {{instruction}}, return only the rewritten inner
        HTML of that fragment, preserving its structure.
        PROMPT;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function promptArgs(): array
    {
        return [
            ['name' => 'brief', 'type' => 'string', 'required' => false, 'description' => 'What the full page should say / be about.'],
            ['name' => 'section_type', 'type' => 'string', 'required' => false, 'description' => 'One allowed section key for single-section generation.'],
            ['name' => 'instruction', 'type' => 'string', 'required' => false, 'description' => 'Edit/generation instruction.'],
            ['name' => 'current_html', 'type' => 'string', 'required' => false, 'description' => 'Existing fragment HTML for inline rewrites.'],
        ];
    }
}
