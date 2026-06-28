<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Services\Data\VariableStore;

/**
 * Persists a value into a global variable (app-wide, surviving the run),
 * optionally mirroring it into a context var for downstream nodes.
 *
 * Node config shape:
 *   {
 *     "key":    "tax_rate",        // global key (interpolated)
 *     "value":  "{{ vars.rate }}", // value (interpolated)
 *     "type":   "number",          // string|number|boolean|json (default string)
 *     "output": "rate"             // optional ctx var to also write
 *   }
 */
class SetVariableNode implements FlowNodeHandler
{
    public function __construct(private readonly VariableStore $store) {}

    public function type(): string
    {
        return 'set_variable';
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,string>
     */
    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);

        $key = (string) $context->interpolateDeep((string) ($config['key'] ?? ''));
        $value = $context->interpolateDeep($config['value'] ?? null);
        $type = isset($config['type']) ? (string) $config['type'] : null;
        $output = (string) ($config['output'] ?? '');

        if ($key !== '') {
            $this->store->set($key, $value, $type);
        }

        if ($output !== '') {
            $context->set($output, $value);
        }

        return (array) ($node['next'] ?? []);
    }
}
