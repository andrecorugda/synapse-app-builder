<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Blocks;

/**
 * One section in the page-builder's fixed block vocabulary.
 *
 * The same definition feeds three consumers: the GrapesJS block manager +
 * component type (so AI/dropped markup becomes a named, editable component),
 * the AI system prompt (the allowed sections + markup convention), and the
 * HTML sanitizer's expectations. The wrapping element always carries
 * data-pb-block="{key}" so every consumer can recognise it.
 */
final readonly class SectionBlock
{
    public function __construct(
        public string $key,
        public string $label,
        public string $category,
        public string $template,
        public string $description = '',
        public string $icon = '',
    ) {}

    /**
     * @return array{key:string,label:string,category:string,template:string,description:string,icon:string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'category' => $this->category,
            'template' => $this->template,
            'description' => $this->description,
            'icon' => $this->icon,
        ];
    }
}
