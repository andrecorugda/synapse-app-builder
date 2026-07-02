<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\ResultActionCatalog;
use Andre\AiPageBuilder\Models\Partial;
use Illuminate\Database\Eloquent\Model;

/**
 * Appends result actions returned to the page runtime.
 * config: { actions: [ {type:setHtml,target,html} | {type:notify,message,level} | {type:alert,title,message}
 *                     | {type:modal,target,action,html} | {type:redirect,url,newTab} | {type:logout,url} ] }
 * Action fields are interpolated against the context (so they can carry AI/HTTP output).
 */
class ResultNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    /**
     * Action types the page runtime knows how to apply from a Result node.
     *
     * State-setting (setState/setStates) is deliberately NOT here: it's the job
     * of the dedicated Set Variable node, which emits its own setState action —
     * offering it here too was redundant. setText is likewise dropped as a
     * duplicate of setHtml (textContent is just html without markup).
     */
    private const ALLOWED = ['setHtml', 'notify', 'alert', 'modal', 'redirect', 'logout', 'addClass', 'removeClass'];

    public function type(): string
    {
        return 'result';
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Result',
            category: CapabilityCategory::Ui,
            description: 'Returns one or more UI actions to the page that triggered the flow, driving the live page without a reload. Each action\'s fields are interpolated, so they can carry AI/HTTP output. Unknown action types are skipped.',
            usage: 'actions [{type:"notify", message:"Saved!", level:"success"}, {type:"alert", title:"Done", message:"Saved."}]. Supported types: setHtml, notify, alert, modal, redirect, logout, addClass, removeClass. (To set page state, use the Set Variable node.)',
            icon: 'bell-alert',
            inputs: [
                new CapabilityInput(
                    'actions',
                    'Actions',
                    'actions',
                    required: true,
                    help: 'Array of action objects, each with a "type" and its type-specific fields. '
                        .'The editor renders a low-code builder; the catalog of available types and their '
                        .'fields is in the options map. Supported types: '
                        .implode(', ', array_keys(ResultActionCatalog::types())).'.',
                    options: ResultActionCatalog::types(),
                ),
            ],
            outputHandles: ['next'],
        );
    }

    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);

        foreach ((array) ($config['actions'] ?? []) as $action) {
            if (! is_array($action)) {
                continue;
            }
            $type = (string) ($action['type'] ?? '');
            if (! in_array($type, self::ALLOWED, true)) {
                continue;
            }

            // A modal action may name a Partial to show as its (designed) body.
            // Resolve the partial's already-sanitized html into `html` BEFORE
            // interpolation, so the partial's own {{ }} tokens resolve against
            // this flow's context. An explicit `html` wins if also set.
            if ($type === 'modal' && ($action['html'] ?? '') === '' && ! empty($action['partial'])) {
                $action['html'] = $this->partialHtml((string) $action['partial']);
            }
            unset($action['partial']);

            /** @var array<string,mixed> $resolved */
            $resolved = $context->interpolateDeep($action);
            $context->addAction($resolved);
        }

        return (array) ($node['next'] ?? []);
    }

    /**
     * The (sanitized-at-save) html of a Partial by slug, or '' if not found.
     */
    private function partialHtml(string $slug): string
    {
        /** @var class-string<Model> $model */
        $model = config('ai-page-builder.models.partial', Partial::class);

        return (string) ($model::query()->where('slug', $slug)->value('html') ?? '');
    }
}
