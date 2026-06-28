<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

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
