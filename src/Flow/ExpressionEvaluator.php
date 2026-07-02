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
            return $this->evaluateOrThrow($expression, $variables);
        } catch (\Throwable $e) {
            Log::warning('[ExpressionEvaluator] Failed to evaluate expression.', [
                'expression' => $expression,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Like {@see evaluate()} but lets errors propagate — used when running a
     * Function, so a failing or asserting function surfaces (and a wrapping
     * Transaction can roll back) instead of silently yielding null.
     *
     * @param  array<string,mixed>  $variables
     */
    public function evaluateOrThrow(string $expression, array $variables = []): mixed
    {
        return $this->el->evaluate($this->normalize($expression), $variables);
    }

    /**
     * Rewrite dot-access on the context roots (vars/input/args/states/globals) to
     * bracket-access, because those roots are ARRAYS and Symfony EL's `foo.bar`
     * only works on objects — arrays require `foo['bar']`. So an author who writes
     * `vars.item['id']` or `vars.order.total` (the natural, forgiving form) gets
     * the same result as the strict `vars['item']['id']`.
     *
     * String literals are left untouched, so a value like `'input.txt'` or a URL
     * inside quotes is never mangled.
     */
    private function normalize(string $expression): string
    {
        // Split on quoted literals (single or double), keeping them as captured
        // delimiters at the odd indices so we only rewrite the code between them.
        $segments = preg_split(
            '/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/',
            $expression,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($segments === false) {
            return $expression;
        }

        $out = '';
        foreach ($segments as $i => $segment) {
            if ($i % 2 === 1) {          // a quoted literal — leave verbatim
                $out .= $segment;

                continue;
            }
            $out .= preg_replace_callback(
                '/\b(vars|input|args|states|globals)((?:\.[a-zA-Z_]\w*)+)/',
                static function (array $m): string {
                    $keys = explode('.', ltrim($m[2], '.'));

                    return $m[1].implode('', array_map(static fn (string $k): string => "['".$k."']", $keys));
                },
                $segment
            );
        }

        return $out;
    }
}
