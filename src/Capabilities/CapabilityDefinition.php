<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities;

/**
 * The single metadata spine for everything the flow/function engine can do.
 *
 * A capability is either a flow NODE (kind 'node', rendered in the canvas drawer)
 * or a function HELPER (kind 'helper', surfaced in the function editor dropdown).
 * Both share the same descriptive shape — label, category, description, usage and
 * a typed input schema — so one definition feeds the builder UI AND the MCP/AI
 * tool listing. Third-party packages register their own definitions through the
 * same registries, so adding a node or helper never requires a core change.
 */
final class CapabilityDefinition
{
    public const KIND_NODE = 'node';

    public const KIND_HELPER = 'helper';

    /**
     * @param  string  $key  node `type` ('http_request') or helper key ('db.create')
     * @param  array<int,CapabilityInput>  $inputs
     * @param  array<int,string>  $outputHandles  canvas output connection points
     *                                            (e.g. ['next'] or ['true','false'])
     * @param  array<string,mixed>  $meta  freeform extras (e.g. example payloads)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly CapabilityCategory $category,
        public readonly string $kind = self::KIND_NODE,
        public readonly string $description = '',
        public readonly string $usage = '',
        public readonly string $icon = '',
        public readonly array $inputs = [],
        public readonly array $outputHandles = ['next'],
        public readonly array $meta = [],
    ) {}

    /** The icon to display: the explicit one, else the category default. */
    public function icon(): string
    {
        return $this->icon !== '' ? $this->icon : $this->category->icon();
    }

    /**
     * Frontend/MCP-friendly serialization. Shared by the node drawer, the helper
     * dropdown, and (later) the AI tool catalogue.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'kind' => $this->kind,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'category_order' => $this->category->order(),
            'description' => $this->description,
            'usage' => $this->usage,
            'icon' => $this->icon(),
            'inputs' => array_map(static fn (CapabilityInput $i): array => $i->toArray(), $this->inputs),
            'output_handles' => $this->outputHandles,
            'meta' => $this->meta,
        ];
    }
}
