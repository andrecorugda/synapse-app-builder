<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Illuminate\Support\Facades\Log;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Thin, sandboxed wrapper around Symfony ExpressionLanguage (v7).
 *
 * No raw PHP, no eval — the only callable surface is the curated helper library
 * (db.* / ui.* / auth.* / util.*) registered from the {@see HelperRegistry}, plus
 * `state()`/`global()`. This is the eval-free power path: a Function composes
 * documented, allow-listed helpers instead of executing arbitrary code.
 *
 * Variables passed by callers (e.g. ['input' => ..., 'vars' => ..., 'args' => ...])
 * are forwarded verbatim to ExpressionLanguage::evaluate().
 */
class ExpressionEvaluator
{
    private readonly ExpressionLanguage $el;

    public function __construct(HelperRegistry $helpers)
    {
        $this->el = new ExpressionLanguage;

        // `state('key')` reads a persistent app-wide State from the store,
        // e.g. state('tax_rate'). Returns null for unknown keys. `global('key')`
        // is kept as an identical alias for backward compatibility.
        $reader = fn (array $arguments, string $key): mixed => app(VariableStore::class)->get($key);
        $compiler = fn (string $key): string => sprintf('app(\Andre\AiPageBuilder\Services\Data\VariableStore::class)->get(%s)', $key);

        $this->el->register('state', $compiler, $reader);
        $this->el->register('global', $compiler, $reader);

        // Expose every registered helper as a sandbox function (db_create, ui_notify, …).
        foreach ($helpers->definitions() as $def) {
            $name = $def->key;
            $this->el->register(
                $name,
                static fn (...$args): string => sprintf('app(\Andre\AiPageBuilder\Capabilities\HelperRegistry::class)->call(%s, %s)', var_export($name, true), implode(', ', $args)),
                static fn (array $values, ...$args): mixed => $helpers->call($name, ...$args),
            );
        }
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
