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

        // `global('key')` reads a persistent app-wide variable from the store,
        // e.g. global('tax_rate'). Returns null for unknown keys.
        $this->el->register(
            'global',
            fn (string $key): string => sprintf('app(\Andre\AiPageBuilder\Services\Data\VariableStore::class)->get(%s)', $key),
            fn (array $arguments, string $key): mixed => app(VariableStore::class)->get($key),
        );
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
