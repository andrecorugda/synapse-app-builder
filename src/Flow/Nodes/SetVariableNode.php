<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
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
class SetVariableNode implements FlowNodeHandler, ProvidesNodeDefinition
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
            // Persist the app-wide State (server-side) …
            $this->store->set($key, $value, $type);
            // … and emit a client action so a page that triggered this flow updates
            // its live reactive store immediately (the runtime applies `setState` to
            // $store.app.<key>) — without this the bound State never changes on screen.
            $context->addAction(['type' => 'setState', 'key' => $key, 'value' => $value]);
        }

        if ($output !== '') {
            $context->set($output, $value);
        }

        return (array) ($node['next'] ?? []);
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Set Variable',
            category: CapabilityCategory::Data,
            description: 'Stores a value in an app-wide global variable that survives the run, optionally mirroring it into a context variable for the next nodes. The value is cast to the chosen type.',
            usage: 'key "tax_rate", value "{{ vars.rate }}", type "number", output "rate" → saves the global and exposes {{ vars.rate }} downstream.',
            icon: 'variable',
            inputs: [
                new CapabilityInput('key', 'Global key', 'string', required: true, help: 'Name of the global variable to write (interpolated).'),
                new CapabilityInput('value', 'Value', 'expression', help: 'The value to store (interpolated).'),
                new CapabilityInput('type', 'Type', 'select', default: 'string', options: [
                    'string' => 'string',
                    'number' => 'number',
                    'boolean' => 'boolean',
                    'json' => 'json',
                ]),
                new CapabilityInput('output', 'Context variable', 'string', help: 'Optional context variable to also receive the value, available to later nodes as {{ vars.<name> }}.'),
            ],
            outputHandles: ['next'],
        );
    }
}
