<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\Flow;
use Illuminate\Support\Facades\Log;

/**
 * Runs another saved flow's definition as a reusable sub-step, sharing the
 * caller's context. This makes flows composable: a transaction or loop can
 * delegate work to a named sub-flow, and the sub-flow reads and writes the same
 * `vars` the caller sees.
 *
 * Node config shape:
 *   {
 *     "flow":   "<slug>",        // author-fixed slug — never interpolated (IDOR guard)
 *     "output": "<var name>"     // optional; var to store the sub-run's vars snapshot
 *   }
 *
 * Cycle guard: before running, the target slug is checked against
 * {@see FlowContext::$callStack}. Direct self-calls and indirect cycles (A→B→A)
 * are blocked with a logged warning and a graceful skip. The existing
 * `flow.max_steps` budget is the backstop for runaway depth.
 */
class CallFlowNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    public function type(): string
    {
        return 'call_flow';
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,string>
     */
    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);

        // Structural field: author-fixed, never interpolated (IDOR guard — same
        // pattern as RecordNode's "model" key). A caller cannot redirect this node
        // to a different flow at runtime by injecting {{ input.flow }}.
        $slug = (string) ($config['flow'] ?? '');
        $output = (string) ($config['output'] ?? '');

        if ($slug === '') {
            return (array) ($node['next'] ?? []);
        }

        // Cycle guard — block direct self-calls AND indirect cycles (A→B→A).
        if (in_array($slug, $context->callStack, true)) {
            Log::warning('[ai-page-builder] call_flow cycle blocked: '.$slug, [
                'call_stack' => $context->callStack,
            ]);

            $context->failed = true;
            $context->error = 'call_flow cycle blocked: '.$slug;

            return (array) ($node['next'] ?? []);
        }

        /** @var class-string<Flow> $modelClass */
        $modelClass = config('ai-page-builder.models.flow', Flow::class);

        /** @var Flow|null $flow */
        $flow = $modelClass::where('slug', $slug)->first();

        if ($flow === null || ! is_array($flow->definition)) {
            // No-op gracefully: missing or empty definition is not an error.
            return (array) ($node['next'] ?? []);
        }

        $definition = $flow->definition;
        $start = (string) ($definition['start'] ?? '');
        $nodes = $definition['nodes'] ?? null;

        if ($start === '' || ! is_array($nodes) || $nodes === []) {
            return (array) ($node['next'] ?? []);
        }

        // Push the slug onto the call stack before running, pop it when done
        // (finally ensures the pop even if the sub-run throws).
        $context->callStack[] = $slug;

        try {
            app(FlowRunner::class)->runBody($definition, $context);
        } finally {
            array_pop($context->callStack);
        }

        // Store a snapshot of vars under the output key when requested.
        if ($output !== '') {
            $context->set($output, $context->vars);
        }

        return (array) ($node['next'] ?? []);
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Run Flow (sub-flow)',
            category: CapabilityCategory::Util,
            description: 'Runs another saved flow as a reusable sub-step, sharing this flow\'s context. The sub-flow reads and writes the same vars, so its outputs are visible to later steps. Circular calls are blocked automatically.',
            usage: 'flow "send-welcome-email" → invokes the named flow inline; its steps execute as if they were part of this flow.',
            icon: 'arrow-top-right-on-square',
            inputs: [
                new CapabilityInput(
                    'flow',
                    'Flow slug',
                    'string',
                    required: true,
                    help: 'Slug of the saved flow to run as a sub-step; shares this flow\'s context. Cannot be the current flow (cycle guard blocks self-calls and indirect cycles).',
                ),
                new CapabilityInput(
                    'output',
                    'Output variable',
                    'string',
                    help: 'Optional context variable to store a snapshot of vars after the sub-flow completes.',
                ),
            ],
            outputHandles: ['next'],
        );
    }
}
