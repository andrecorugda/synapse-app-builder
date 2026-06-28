<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Ai;

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Flow\NodeRegistry;

/**
 * Structural validation for a build plan before the applier touches the DB.
 *
 * Returns a flat list of human-readable error strings (empty = valid). The
 * intent is to BLOCK structural breakage — bad slugs, unknown field types,
 * unknown flow node types — while staying lenient on things that are merely
 * advisory (an unrecognised data-pb-block key on a page is reported but, per
 * the contract, callers may choose to treat it as a warning).
 */
class BuildPlanValidator
{
    /** State value types accepted by VariableStore / Variable. */
    private const STATE_TYPES = ['string', 'number', 'boolean', 'json'];

    /** Function runtimes accepted by FunctionNode. */
    private const FUNCTION_RUNTIMES = ['expression', 'callable', 'php'];

    /** Flow trigger types accepted by the dispatcher / Filament. */
    private const TRIGGER_TYPES = ['manual', 'component', 'form', 'cron', 'api', 'collection'];

    /**
     * Fallback node types, used when no booted NodeRegistry is available (e.g.
     * validating outside a fully-booted app). Kept in sync with src/Flow/Nodes.
     *
     * @var array<int,string>
     */
    private const FALLBACK_NODE_TYPES = [
        'trigger', 'condition', 'function', 'http_request',
        'record', 'result', 'set_variable', 'ai_invoke',
    ];

    public function __construct(
        private readonly ?NodeRegistry $nodes = null,
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    public function validate(array $plan): array
    {
        $build = BuildPlan::fromArray($plan);
        $errors = [];

        $this->validateCollections($build, $errors);
        $this->validateStates($build, $errors);
        $this->validateFunctions($build, $errors);
        $this->validateFlows($build, $errors);
        $this->validatePages($build, $errors);

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateCollections(BuildPlan $build, array &$errors): void
    {
        $seenKeys = [];
        $fieldTypes = array_map(static fn (FieldType $t): string => $t->value, FieldType::cases());

        foreach ($build->collections() as $i => $collection) {
            $key = $collection['key'] ?? null;

            if (! is_string($key) || ! $this->isValidSlug($key)) {
                $errors[] = "collections[{$i}]: key '".$this->display($key)."' is not a valid slug (lowercase letters, digits, underscore/hyphen; must start with a letter).";

                continue;
            }

            if (isset($seenKeys[$key])) {
                $errors[] = "collections[{$i}]: duplicate collection key '{$key}'.";
            }
            $seenKeys[$key] = true;

            $fields = $collection['fields'] ?? [];
            if (! is_array($fields) || $fields === []) {
                $errors[] = "collections[{$i}] ('{$key}'): must define at least one field.";

                continue;
            }

            $seenFieldKeys = [];
            foreach ($fields as $fi => $field) {
                if (! is_array($field)) {
                    $errors[] = "collections[{$i}] ('{$key}').fields[{$fi}]: must be an object.";

                    continue;
                }

                $fieldKey = $field['key'] ?? null;
                if (! is_string($fieldKey) || ! $this->isValidSlug($fieldKey)) {
                    $errors[] = "collections[{$i}] ('{$key}').fields[{$fi}]: key '".$this->display($fieldKey)."' is not a valid slug.";
                } else {
                    if (isset($seenFieldKeys[$fieldKey])) {
                        $errors[] = "collections[{$i}] ('{$key}'): duplicate field key '{$fieldKey}'.";
                    }
                    $seenFieldKeys[$fieldKey] = true;
                }

                $type = $field['type'] ?? null;
                if (! is_string($type) || ! in_array($type, $fieldTypes, true)) {
                    $errors[] = "collections[{$i}] ('{$key}').fields[{$fi}]: type '".$this->display($type)."' is not a valid field type (".implode(', ', $fieldTypes).').';
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateStates(BuildPlan $build, array &$errors): void
    {
        $seen = [];

        foreach ($build->states() as $i => $state) {
            $key = $state['key'] ?? null;
            if (! is_string($key) || $key === '') {
                $errors[] = "states[{$i}]: key '".$this->display($key)."' is required.";
            } else {
                if (isset($seen[$key])) {
                    $errors[] = "states[{$i}]: duplicate state key '{$key}'.";
                }
                $seen[$key] = true;
            }

            // type is optional (VariableStore infers it), but if present it must be valid.
            if (array_key_exists('type', $state) && $state['type'] !== null) {
                $type = $state['type'];
                if (! is_string($type) || ! in_array($type, self::STATE_TYPES, true)) {
                    $errors[] = "states[{$i}]: type '".$this->display($type)."' must be one of ".implode(', ', self::STATE_TYPES).'.';
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateFunctions(BuildPlan $build, array &$errors): void
    {
        $seen = [];

        foreach ($build->functions() as $i => $fn) {
            $slug = $fn['slug'] ?? null;
            if (! is_string($slug) || ! $this->isValidSlug($slug)) {
                $errors[] = "functions[{$i}]: slug '".$this->display($slug)."' is not a valid slug.";
            } else {
                if (isset($seen[$slug])) {
                    $errors[] = "functions[{$i}]: duplicate function slug '{$slug}'.";
                }
                $seen[$slug] = true;
            }

            $runtime = $fn['runtime'] ?? null;
            if (! is_string($runtime) || ! in_array($runtime, self::FUNCTION_RUNTIMES, true)) {
                $errors[] = "functions[{$i}]: runtime '".$this->display($runtime)."' must be one of ".implode(', ', self::FUNCTION_RUNTIMES).'.';
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateFlows(BuildPlan $build, array &$errors): void
    {
        $seen = [];
        $nodeTypes = $this->nodeTypes();

        foreach ($build->flows() as $i => $flow) {
            $slug = $flow['slug'] ?? null;
            if (! is_string($slug) || ! $this->isValidSlug($slug)) {
                $errors[] = "flows[{$i}]: slug '".$this->display($slug)."' is not a valid slug.";
            } else {
                if (isset($seen[$slug])) {
                    $errors[] = "flows[{$i}]: duplicate flow slug '{$slug}'.";
                }
                $seen[$slug] = true;
            }

            $triggerType = $flow['trigger_type'] ?? null;
            if (! is_string($triggerType) || ! in_array($triggerType, self::TRIGGER_TYPES, true)) {
                $errors[] = "flows[{$i}]: trigger_type '".$this->display($triggerType)."' must be one of ".implode(', ', self::TRIGGER_TYPES).'.';
            }

            $definition = $flow['definition'] ?? null;
            if (! is_array($definition)) {
                $errors[] = "flows[{$i}]: definition must be an object with 'nodes'.";

                continue;
            }

            $nodes = $definition['nodes'] ?? null;
            if (! is_array($nodes) || $nodes === []) {
                $errors[] = "flows[{$i}]: definition.nodes must be a non-empty object.";

                continue;
            }

            foreach ($nodes as $nodeId => $node) {
                if (! is_array($node)) {
                    $errors[] = "flows[{$i}].nodes['{$nodeId}']: must be an object.";

                    continue;
                }

                $type = $node['type'] ?? null;
                if (! is_string($type) || ! in_array($type, $nodeTypes, true)) {
                    $errors[] = "flows[{$i}].nodes['{$nodeId}']: type '".$this->display($type)."' is not a registered node type (".implode(', ', $nodeTypes).').';
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validatePages(BuildPlan $build, array &$errors): void
    {
        $seen = [];
        $blockKeys = $this->knownBlockKeys();

        foreach ($build->pages() as $i => $page) {
            $slug = $page['slug'] ?? null;
            if (! is_string($slug) || ! $this->isValidSlug($slug)) {
                $errors[] = "pages[{$i}]: slug '".$this->display($slug)."' is not a valid slug.";
            } else {
                if (isset($seen[$slug])) {
                    $errors[] = "pages[{$i}]: duplicate page slug '{$slug}'.";
                }
                $seen[$slug] = true;
            }

            $status = $page['status'] ?? null;
            if ($status !== null && ! in_array($status, ['draft', 'published'], true)) {
                $errors[] = "pages[{$i}]: status '".$this->display($status)."' must be 'draft' or 'published'.";
            }

            // data-pb-block references are advisory: report unknown keys but
            // never block (the contract treats these as warnings).
            $html = $page['html'] ?? '';
            if (is_string($html) && $html !== '') {
                foreach ($this->referencedBlockKeys($html) as $blockKey) {
                    if (! in_array($blockKey, $blockKeys, true)) {
                        $errors[] = "pages[{$i}] (warning): references unknown data-pb-block '{$blockKey}'.";
                    }
                }
            }
        }
    }

    /**
     * Registered node types from the booted registry, or the static fallback.
     *
     * @return array<int,string>
     */
    private function nodeTypes(): array
    {
        $types = $this->nodes?->types() ?? [];

        return $types === [] ? self::FALLBACK_NODE_TYPES : $types;
    }

    /**
     * Section keys (the AI vocabulary) plus component / form / data block keys
     * referenced via data-pb-block.
     *
     * @return array<int,string>
     */
    private function knownBlockKeys(): array
    {
        $keys = [];
        foreach (BlockVocabulary::all() as $block) {
            $keys[] = $block->key;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function referencedBlockKeys(string $html): array
    {
        if (! preg_match_all('/data-pb-block\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Slug rule shared by collection/field/function/flow/page keys: starts with
     * a lowercase letter, then lowercase letters, digits, underscore or hyphen.
     */
    private function isValidSlug(string $value): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_-]*$/', $value);
    }

    private function display(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return gettype($value);
    }
}
