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
use Andre\AiPageBuilder\Flow\Nodes\Concerns\ResolvesFlowBody;
use RuntimeException;

/**
 * Iterates over an array and runs its body sub-graph once per element.
 * config: { over, item_var, index_var, max_iterations, body:{start,nodes}, output }
 *
 * `over` is a context path (e.g. `vars.items`, or `{{ vars.items }}`) resolving to
 * the array to walk. Each pass binds the current element to `item_var` (default
 * `item`) and its position to `index_var` (default `index`) before running the
 * body against the SAME context — so a body's record writes / vars accumulate.
 *
 * Failure: if the body fails on an iteration the loop stops and re-throws, so the
 * surrounding handler decides what happens — most usefully, a {@see TransactionNode}
 * wrapping the loop rolls every prior write back. The body also shares the global
 * `flow.max_steps` budget, and `max_iterations` caps the pass count independently.
 */
class LoopNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    use ResolvesFlowBody;

    /** Hard ceiling on iterations regardless of configured max_iterations. */
    private const ITERATION_CEILING = 10000;

    public function type(): string
    {
        return 'loop';
    }

    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);

        $items = $this->resolveItems($context, (string) ($config['over'] ?? ''));
        $body = $this->resolveBody($config);

        $itemVar = (string) ($config['item_var'] ?? 'item') ?: 'item';
        $indexVar = (string) ($config['index_var'] ?? 'index') ?: 'index';
        $maxIterations = min(
            max(0, (int) ($config['max_iterations'] ?? self::ITERATION_CEILING)),
            self::ITERATION_CEILING,
        );

        $runner = app(FlowRunner::class);
        $count = 0;

        foreach ($items as $key => $element) {
            if ($count >= $maxIterations) {
                break;
            }

            $context->set($itemVar, $element);
            $context->set($indexVar, $count);
            $context->set($itemVar.'_key', $key);

            if ($body !== null) {
                $runner->runBody($body, $context);

                if ($context->failed) {
                    // Surface the per-item failure to the enclosing handler.
                    throw new RuntimeException($context->error ?? 'Loop body failed on iteration '.$count.'.');
                }
            }

            $count++;
        }

        $output = (string) ($config['output'] ?? '');
        if ($output !== '') {
            $context->set($output, ['count' => $count]);
        }

        return (array) ($node['next'] ?? []);
    }

    /**
     * Resolve `over` to an iterable. Accepts a bare context path or a single
     * `{{ path }}` token; non-array results yield an empty list.
     *
     * @return iterable<int|string,mixed>
     */
    private function resolveItems(FlowContext $context, string $over): iterable
    {
        $path = trim($over);
        if (preg_match('/^\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}$/', $path, $m) === 1) {
            $path = $m[1];
        }

        if ($path === '') {
            return [];
        }

        $value = $context->get($path);

        return is_array($value) ? $value : [];
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Loop',
            category: CapabilityCategory::FlowControl,
            description: 'Runs its body once for each element of an array. Each pass exposes the current element and index to the body, and body writes accumulate into the run. Wrap a Loop in a Transaction to make all its writes atomic.',
            usage: 'over "vars.cart_items" → for each line item, the body reads {{ vars.item }} (and {{ vars.index }}) to decrement that product\'s stock.',
            icon: 'arrow-path',
            inputs: [
                new CapabilityInput('over', 'Array to loop over', 'expression', required: true, help: 'A context path resolving to an array, e.g. vars.items or input.lines. Accepts a bare path or a single {{ path }} token.'),
                new CapabilityInput('item_var', 'Item variable', 'string', default: 'item', help: 'The body reads the current element as {{ vars.<item_var> }}. Also exposes {{ vars.<item_var>_key }} for the array key.'),
                new CapabilityInput('index_var', 'Index variable', 'string', default: 'index', help: 'Zero-based position of the current element, exposed as {{ vars.<index_var> }}.'),
                new CapabilityInput('max_iterations', 'Max iterations', 'number', default: self::ITERATION_CEILING, help: 'Safety cap on the number of passes (hard ceiling: '.self::ITERATION_CEILING.'). Prevents runaway loops.'),
                new CapabilityInput('body', 'Body (sub-flow)', 'json', help: 'A {start, nodes} sub-graph executed once per element. The canvas "body" handle wires to the first node; this JSON field is the serialised sub-graph used when importing/exporting.'),
                new CapabilityInput('output', 'Output variable', 'string', interpolated: false, help: 'Optional context variable that receives {count: <iterations>} after the loop completes.'),
            ],
            outputHandles: ['body', 'next'],
            meta: ['has_body' => true],
        );
    }
}
