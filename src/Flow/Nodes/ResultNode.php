<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\FlowContext;

/**
 * Appends result actions returned to the page runtime.
 * config: { actions: [ {type:setHtml,target,html} | {type:notify,message,level} | {type:redirect,url}
 *                     | {type:setState,key,value} | {type:setStates,values:{...}} ] }
 * Action fields are interpolated against the context (so they can carry AI/HTTP output).
 */
class ResultNode implements FlowNodeHandler
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
