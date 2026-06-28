<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\ExpressionEvaluator;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Models\FlowFunction;

/**
 * Executes a named FlowFunction and stores the result in a context variable.
 *
 * Node config shape:
 *   {
 *     "function": "<slug>",          // slug of the FlowFunction record
 *     "args":     { "key": "val" },  // interpolated before execution
 *     "output":   "varName"          // ctx var to write the result into (default: "result")
 *   }
 *
 * Two runtimes are supported:
 *   expression — body is evaluated as a Symfony ExpressionLanguage expression.
 *                Variables exposed: input (ctx.input), vars (ctx.vars), args (interpolated args).
 *   callable   — body is a key in FunctionRegistry; the callable receives ($args, $ctx).
 */
class FunctionNode implements FlowNodeHandler
{
    public function __construct(
        private readonly FunctionRegistry $registry,
        private readonly ExpressionEvaluator $evaluator,
    ) {}

    public function type(): string
    {
        return 'function';
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<int,string>
     */
    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);

        $slug = (string) ($config['function'] ?? '');
        $output = (string) ($config['output'] ?? 'result');

        /** @var array<string,mixed> $args */
        $args = $context->interpolateDeep((array) ($config['args'] ?? []));

        $result = null;

        if ($slug !== '') {
            /** @var class-string<FlowFunction> $modelClass */
            $modelClass = config('ai-page-builder.models.flow_function', FlowFunction::class);

            /** @var FlowFunction|null $fn */
            $fn = $modelClass::where('slug', $slug)->first();

            if ($fn !== null) {
                if ($fn->runtime === 'expression') {
                    $result = $this->evaluator->evaluate(
                        (string) $fn->body,
                        [
                            'input' => $context->input,
                            'vars' => $context->vars,
                            'args' => $args,
                        ]
                    );
                } elseif ($fn->runtime === 'callable') {
                    $cb = $this->registry->get((string) $fn->body);

                    if ($cb !== null) {
                        $result = $cb($args, $context);
                    }
                }
            }
        }

        $context->set($output, $result);

        return (array) ($node['next'] ?? []);
    }
}
