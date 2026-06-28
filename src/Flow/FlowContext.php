<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Services\Data\VariableStore;

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

    /** @param array<string,mixed> $input */
    public function __construct(public array $input = []) {}

    public function set(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
    }

    /** Resolve a dotted path like `input.brief`, `vars.ai`, or `globals.tax_rate`. */
    public function get(string $path): mixed
    {
        [$root, $rest] = array_pad(explode('.', $path, 2), 2, null);
        $base = match ($root) {
            'input' => $this->input,
            'vars' => $this->vars,
            // Persistent, app-wide globals. Resolved lazily so reading the
            // store (and thus hitting the DB) only happens when referenced.
            'globals' => app(VariableStore::class)->all(),
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

    public function addAction(array $action): void
    {
        $this->actions[] = $action;
    }
}
