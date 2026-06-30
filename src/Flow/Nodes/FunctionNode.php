<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\ExpressionEvaluator;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Illuminate\Support\Facades\Log;

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
class FunctionNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    public function __construct(
        private readonly FunctionRegistry $registry,
        private readonly ExpressionEvaluator $evaluator,
    ) {}

    public function type(): string
    {
        return 'function';
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Run Function',
            category: CapabilityCategory::Util,
            description: 'Runs a saved reusable function (referenced by its slug) and stores the return value in a context variable. The function body can be an expression, a registered callable, or PHP, depending on how it was defined.',
            usage: 'function "calc-discount", args {amount: "{{ input.total }}"}, output "discount" → exposes {{ vars.discount }} to later nodes.',
            icon: 'wrench-screwdriver',
            inputs: [
                new CapabilityInput('function', 'Function', 'string', required: true, help: 'Slug of the saved function to execute.'),
                new CapabilityInput('args', 'Arguments', 'keyvalue', help: 'Key/value arguments passed to the function (interpolated). Exposed inside the function as args.'),
                new CapabilityInput('output', 'Output variable', 'string', default: 'result', help: 'Context variable to receive the return value (default "result").'),
            ],
            outputHandles: ['next'],
        );
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
                    // `states` is the primary name; `globals` is kept as an
                    // identical alias for backward compatibility. Errors PROPAGATE
                    // (evaluateOrThrow) so a failing/asserting function surfaces to
                    // the engine — letting a wrapping Transaction roll back.
                    $states = app(VariableStore::class)->all();

                    $result = $this->evaluator->evaluateOrThrow(
                        (string) $fn->body,
                        [
                            'input' => $context->input,
                            'vars' => $context->vars,
                            'args' => $args,
                            'states' => $states,
                            'globals' => $states,
                        ]
                    );
                } elseif ($fn->runtime === 'callable') {
                    $cb = $this->registry->get((string) $fn->body);

                    if ($cb !== null) {
                        $result = $cb($args, $context);
                    }
                } elseif ($fn->runtime === 'php') {
                    $result = $this->runPhp((string) $fn->body, $args, $context);
                }
            }
        }

        $context->set($output, $result);

        return (array) ($node['next'] ?? []);
    }

    /**
     * Execute a raw-PHP function body. Synapse App Builder is a self-hosted,
     * single-tenant builder where the function author IS the app owner (who
     * already has full code/server access), so this is intentional power — but
     * it executes arbitrary PHP, so it's gated behind a config flag that a
     * cautious deployer can switch off. The body runs in an isolated static
     * closure with $args / $input / $vars / $states (and $globals, an identical
     * alias) available and should `return` a value.
     *
     * @param  array<string,mixed>  $args
     */
    private function runPhp(string $body, array $args, FlowContext $context): mixed
    {
        if (! (bool) config('ai-page-builder.flow.allow_php_functions', false)) {
            return null;
        }

        try {
            $exec = static function (array $args, array $input, array $vars, array $states, array $globals) use ($body) {
                return eval($body);
            };

            // `$states` is the primary name; `$globals` is kept as an identical
            // alias for backward compatibility.
            $states = app(VariableStore::class)->all();

            return $exec($args, $context->input, $context->vars, $states, $states);
        } catch (\Throwable $e) {
            Log::warning('[ai-page-builder] php function failed: '.$e->getMessage());

            return null;
        }
    }
}
