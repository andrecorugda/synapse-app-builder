<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Services\Data\VariableStore;
use Illuminate\Support\Facades\Log;

/**
 * Carries state through a flow run: the trigger `input`, accumulated `vars`
 * (set by nodes), and the `actions` returned to the page. Supports `{{...}}`
 * interpolation against input/vars/globals in node configs.
 */
class FlowContext
{
    /** @var array<string,mixed> */
    public array $vars = [];

    /** @var array<int,array<string,mixed>> */
    public array $actions = [];

    /** @var array<int,array<string,mixed>> */
    public array $steps = [];

    /**
     * Global executed-node counter, shared across the main walk AND every nested
     * loop/transaction body so the `flow.max_steps` budget bounds the run as a
     * whole — a runaway loop hits the same cap instead of spinning forever.
     */
    public int $stepCount = 0;

    /** Set when a node failed and no on-error branch handled it. */
    public bool $failed = false;

    /** Message of the unhandled failure (if any). */
    public ?string $error = null;

    /** Id of the node whose failure went unhandled (if any). */
    public ?string $failedNode = null;

    /**
     * Call stack of flow slugs currently executing, used by {@see CallFlowNode}
     * to detect direct and indirect cycles (A→A, A→B→A) before running a
     * referenced sub-flow. The shared `flow.max_steps` budget is the primary
     * runaway guard; this list is a cheap, explicit cycle detector.
     *
     * @var array<int,string>
     */
    public array $callStack = [];

    /**
     * @param  array<string,mixed>  $input  The trigger payload.
     * @param  array<string,mixed>  $stateOverlay  Per-run values that shadow the
     *                                             persisted States for `{{ states.* }}` resolution. Used for component
     *                                             (page-button) triggers, where the page's live $store.app state IS the
     *                                             authoritative state at trigger time (never persisted server-side).
     */
    public function __construct(public array $input = [], public array $stateOverlay = []) {}

    /**
     * Persisted app-wide States, with any per-run overlay applied on top.
     *
     * @return array<string,mixed>
     */
    private function states(): array
    {
        $persisted = app(VariableStore::class)->all();

        return $this->stateOverlay === [] ? $persisted : array_replace($persisted, $this->stateOverlay);
    }

    public function set(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
    }

    /** Resolve a dotted path like `input.brief`, `vars.ai`, or `states.tax_rate`. */
    public function get(string $path): mixed
    {
        [$root, $rest] = array_pad(explode('.', $path, 2), 2, null);
        $base = match ($root) {
            'input' => $this->input,
            'vars' => $this->vars,
            // Persistent, app-wide States (a.k.a. globals — kept as an alias for
            // backward compatibility). Resolved lazily so reading the store (and
            // thus hitting the DB) only happens when referenced.
            'states', 'globals' => $this->states(),
            default => null,
        };

        if ($rest === null) {
            return $base;
        }

        return data_get($base, $rest);
    }

    /** Replace every `{{ path }}` token in a string with its resolved value. */
    public function interpolate(string $template): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $m): string {
            $v = $this->get($m[1]);

            return is_scalar($v) ? (string) $v : (is_array($v) ? json_encode($v) : '');
        }, $template);
    }

    /** Deep-interpolate every string in a config array. */
    public function interpolateDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->interpolate($value);
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->interpolateDeep($v), $value);
        }

        return $value;
    }

    /**
     * Resolve a value-bearing config leaf (record `data`/`id`/`filter`, function
     * `args`). Unlike {@see interpolateDeep} — which only fills `{{ path }}`
     * tokens — this also evaluates a BARE Symfony-EL expression, so the natural
     * things an author (or the AI) writes all work in one place:
     *
     *   "{{ input.name }}"          → interpolated string (as before)
     *   "vars.order['id']"          → evaluated → the real id (type preserved)
     *   "'ORD-' ~ util_now('Ymd')"  → evaluated → the built string
     *   0 / "open" / "completed"    → plain literal, passed through untouched
     *
     * A value that "looks like" an expression but fails to evaluate falls back to
     * the raw string (logged) rather than becoming null — a misclassified literal
     * survives instead of silently vanishing.
     */
    public function resolveDynamic(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->resolveDynamic($v), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        // `{{ }}` tokens keep their string-interpolation semantics.
        if (str_contains($value, '{{')) {
            return $this->interpolate($value);
        }

        if (! $this->looksLikeExpression($value)) {
            return $value;
        }

        $states = $this->states();

        try {
            return app(ExpressionEvaluator::class)->evaluateOrThrow($value, [
                'input' => $this->input,
                'vars' => $this->vars,
                'states' => $states,
                'globals' => $states,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ai-page-builder] dynamic value eval failed; treating as literal.', [
                'value' => $value,
                'error' => $e->getMessage(),
            ]);

            return $value;
        }
    }

    /** Deep variant of {@see resolveDynamic} for a config sub-tree. */
    public function resolveDynamicDeep(mixed $value): mixed
    {
        return $this->resolveDynamic($value);
    }

    /**
     * Cheap, conservative test for "this string is a Symfony-EL expression, not a
     * plain literal". Deliberately narrow so ordinary text values ("completed",
     * "Order placed!") are never mistaken for code.
     */
    private function looksLikeExpression(string $value): bool
    {
        $t = trim($value);

        if ($t === '') {
            return false;
        }

        // A context root immediately followed by property/index access:
        // vars.x, vars['x'], input.y, args['z'], states.k
        if (preg_match('/^(vars|input|args|states|globals)\s*(\.|\[)/', $t) === 1) {
            return true;
        }

        // Begins with a quoted string literal (only meaningful inside an EL
        // expression, e.g. a concatenation "'ORD-' ~ …").
        if ($t[0] === "'") {
            return true;
        }

        // A helper / function call: db_*, ui_*, auth_*, util_*, state(), global().
        if (preg_match('/\b(db|ui|auth|util)_\w+\s*\(|\b(state|global)\s*\(/', $t) === 1) {
            return true;
        }

        // An EL operator sitting between tokens (concat / arithmetic / comparison).
        if (preg_match('/\S\s*(~|\*|\/|%|>=|<=|==|!=|>|<)\s*\S|\S\s[-+]\s\S/', $t) === 1) {
            return true;
        }

        return false;
    }

    public function addAction(array $action): void
    {
        $this->actions[] = $action;
    }
}
