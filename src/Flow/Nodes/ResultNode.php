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

/**
 * Appends result actions returned to the page runtime.
 * config: { actions: [ {type:setHtml,target,html} | {type:notify,message,level} | {type:redirect,url}
 *                     | {type:setState,key,value} | {type:setStates,values:{...}} ] }
 * Action fields are interpolated against the context (so they can carry AI/HTTP output).
 */
class ResultNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    /**
     * Action types the page runtime knows how to apply. setState/setStates push
     * live values into the published page's reactive Alpine store ($store.app),
     * so a flow can drive bound components without a reload.
     */
    private const ALLOWED = ['setHtml', 'setText', 'notify', 'redirect', 'logout', 'addClass', 'removeClass', 'setState', 'setStates'];

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
            usage: 'actions [{type:"notify", message:"Saved!", level:"success"}, {type:"setState", key:"saved", value:true}]. Supported types: setHtml, setText, notify, redirect, logout, addClass, removeClass, setState, setStates.',
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
            /** @var array<string,mixed> $resolved */
            $resolved = $context->interpolateDeep($action);
            $context->addAction($resolved);
        }

        return (array) ($node['next'] ?? []);
    }
}
