<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Blocks;

use Andre\AiPageBuilder\Capabilities\CapabilityInput;

/**
 * One author-configurable setting a component (block) declares for itself.
 *
 * Mirrors the flow-node {@see CapabilityInput}
 * pattern for components: a registered/premium block can ship its own settings,
 * which the GrapesJS editor renders as traits (in the block's category, or a
 * chosen one) on the selected component. Each setting writes a plain attribute
 * named `key` on the component — sanitizer-safe by default — which the block's
 * template / custom_js reads at render time.
 *
 * `type` maps to a GrapesJS trait type: text | number | checkbox | select
 * (select uses `options` as an id => label map).
 */
final readonly class ComponentSetting
{
    /**
     * @param  string  $type  text|number|checkbox|select
     * @param  array<string,string>  $options  for `select`: value => label
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public array $options = [],
        public string $category = 'Settings',
        public mixed $default = null,
    ) {}

    public static function make(string $key, string $label, string $type = 'text'): self
    {
        return new self($key, $label, $type);
    }

    /**
     * @return array{key:string,label:string,type:string,options:array<string,string>,category:string,default:mixed}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => $this->options,
            'category' => $this->category,
            'default' => $this->default,
        ];
    }
}
