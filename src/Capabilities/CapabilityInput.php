<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities;

/**
 * One configurable input of a capability — a field in a flow node's config form
 * or an argument of a function helper. The same schema drives three consumers:
 *   - the node drawer's config panel (which control to render),
 *   - the helper dropdown's argument hints,
 *   - the MCP/AI tool schema (so an agent knows what to pass).
 */
final class CapabilityInput
{
    /**
     * @param  string  $type  one of: string, text, number, boolean, select, json,
     *                        expression, collection, keyvalue, code
     * @param  array<int|string,mixed>  $options  for `select`: value => label map
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'string',
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly string $help = '',
        public readonly array $options = [],
        public readonly bool $interpolated = true,
    ) {}

    /** Convenience constructor for the common case. */
    public static function make(string $key, string $label, string $type = 'string'): self
    {
        return new self($key, $label, $type);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'help' => $this->help,
            'options' => $this->options,
            'interpolated' => $this->interpolated,
        ];
    }
}
