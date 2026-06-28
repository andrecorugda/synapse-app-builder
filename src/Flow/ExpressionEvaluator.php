<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Services\Data\VariableStore;
use Illuminate\Support\Facades\Log;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Thin, sandboxed wrapper around Symfony ExpressionLanguage (v7).
 *
 * Only pure expression evaluation is exposed — no PHP functions, no eval,
 * no file/DB/exec/network access. The ExpressionLanguage sandbox provides
 * safe arithmetic, comparison, string operations, and array access.
 *
 * Variables passed by callers (e.g. ['input' => ..., 'vars' => ..., 'args' => ...])
 * are forwarded verbatim to ExpressionLanguage::evaluate().
 */
class ExpressionEvaluator
{
    private readonly ExpressionLanguage $el;

    public function __construct()
    {
        $this->el = new ExpressionLanguage;

        // `state('key')` reads a persistent app-wide State from the store,
        // e.g. state('tax_rate'). Returns null for unknown keys. `global('key')`
        // is kept as an identical alias for backward compatibility.
        $reader = fn (array $arguments, string $key): mixed => app(VariableStore::class)->get($key);
        $compiler = fn (string $key): string => sprintf('app(\Andre\AiPageBuilder\Services\Data\VariableStore::class)->get(%s)', $key);

        $this->el->register('state', $compiler, $reader);
        $this->el->register('global', $compiler, $reader);
    }

    /**
     * Evaluate a Symfony ExpressionLanguage expression string.
     *
     * Returns null (and logs a warning) on any error — never throws.
     *
     * @param  array<string,mixed>  $variables
     */
    public function evaluate(string $expression, array $variables = []): mixed
    {
        try {
            return $this->el->evaluate($expression, $variables);
        } catch (\Throwable $e) {
            Log::warning('[ExpressionEvaluator] Failed to evaluate expression.', [
                'expression' => $expression,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
