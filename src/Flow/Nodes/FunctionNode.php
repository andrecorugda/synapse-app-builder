<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
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
                            'globals' => app(VariableStore::class)->all(),
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
     * closure with $args / $input / $vars / $globals available and should
     * `return` a value.
     *
     * @param  array<string,mixed>  $args
     */
    private function runPhp(string $body, array $args, FlowContext $context): mixed
    {
        if (! (bool) config('ai-page-builder.flow.allow_php_functions', false)) {
            return null;
        }

        try {
            $exec = static function (array $args, array $input, array $vars, array $globals) use ($body) {
                return eval($body);
            };

            return $exec($args, $context->input, $context->vars, app(VariableStore::class)->all());
        } catch (\Throwable $e) {
            Log::warning('[ai-page-builder] php function failed: '.$e->getMessage());

            return null;
        }
    }
}
