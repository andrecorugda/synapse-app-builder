<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Ai;

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Blocks\SectionBlock;
use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Flow\NodeRegistry;

/**
 * Builds the STATIC engine system prompt for Synapse — App Builder.
 *
 * Everything here is generated from the package's own sources of truth — the
 * block vocabulary, the field-type enum and the registered flow nodes — so the
 * prompt can never drift from what the engine actually supports as components,
 * field types or nodes are added. The output is fully deterministic (no
 * randomness, stable ordering): calling build() twice yields the same string,
 * which is what lets it be seeded as the AI integration's system prompt and
 * diffed across versions.
 *
 * The prompt teaches the model three things:
 *   1. its role — it is the build engine and emits ONLY a JSON build plan;
 *   2. the exact build-plan contract (shape + one compact example);
 *   3. the catalog it is constrained to — components (data-pb-block keys),
 *      collection field types and flow node types — plus the hard rules.
 */
final class SystemPromptBuilder
{
    /**
     * One-line config hints per flow node type, mirroring each node's docblock.
     * Keyed by the node's type() string. Any registered node type without an
     * entry is still listed (so the catalog stays complete) but with a generic
     * hint, which keeps this map from silently going stale as nodes are added.
     *
     * @var array<string,string>
     */
    private const NODE_HINTS = [
        'trigger' => 'Entry node. config: {} — hands off to "next".',
        'record' => 'Read/write a collection. config: { model:"<collection key>", operation:"list|get|create|update|delete", id, filter, data, output }.',
        'set_variable' => 'Persist an app state. config: { key, value, type:"string|number|boolean|json", output }.',
        'condition' => 'Branch on a comparison. config: { left, op:"equals|not_equals|contains|gt|lt|empty|not_empty", right }. Routes to "next_true" / "next_false".',
        'http_request' => 'Call an external endpoint. config: { method, url, headers:{}, body:{}, output }.',
        'ai_invoke' => 'Call an AI integration through the gateway. config: { integration:"<slug>", args:{}, output }.',
        'function' => 'Run a named function. config: { function:"<slug>", args:{}, output }.',
        'result' => 'Return page actions. config: { actions:[ {type:"setHtml|setText|notify|redirect|addClass|removeClass", ...} ] }.',
    ];

    public function build(): string
    {
        $sections = [
            $this->intro(),
            $this->contract(),
            $this->example(),
            $this->componentCatalog(),
            $this->fieldTypes(),
            $this->nodeTypes(),
            $this->rules(),
        ];

        return implode("\n\n", $sections)."\n";
    }

    private function intro(): string
    {
        return <<<'TXT'
        # Synapse — App Builder: build engine

        You are the build engine for Synapse, a self-hosted app builder. From a
        natural-language request you produce a single application "build plan".

        You output ONLY a JSON object matching the build-plan contract below — no
        prose, no markdown, no code fences, no comments. Every key you emit must
        come from the catalogs in this prompt. Reference only collections, states,
        functions and flows that already exist (see the app context provided per
        request) or that you define in the SAME plan.
        TXT;
    }

    private function contract(): string
    {
        return <<<'TXT'
        ## Build-plan contract

        The top-level JSON object has these OPTIONAL lists — include only what the
        request needs; omit the rest (do not emit empty lists for completeness):

        - "collections": [ {
            "key": "<lowercase_snake key>",
            "name": "<human label>",
            "has_timestamps": <bool>,
            "has_soft_deletes": <bool>,
            "fields": [ {
              "key": "<lowercase_snake>",
              "label": "<human label>",
              "type": "<field type>",
              "options": { "required": <bool>, "unique": <bool>, "default": <any>,
                           "length": <int>, "choices": ["..."],
                           "relation_model": "<collection key>" }
            } ],
            "seed": [ { "<field key>": <value> } ]
          } ]
        - "states": [ { "key": "<lowercase_snake>", "type": "string|number|boolean|json", "value": <any> } ]
        - "functions": [ { "slug": "<lowercase-slug>", "name": "<label>",
                           "runtime": "expression|callable|php", "body": "<string>" } ]
        - "flows": [ {
            "slug": "<lowercase-slug>", "name": "<label>",
            "trigger_type": "manual|component|collection|cron|api",
            "trigger_config": { ... },
            "definition": {
              "start": "<node id>",
              "nodes": { "<node id>": { "type": "<node type>", "config": { ... },
                         "next": "<node id>" | "next_true"/"next_false": "<node id>" } }
            }
          } ]
        - "pages": [ { "slug": "<lowercase-slug>", "title": "<label>",
                       "status": "draft|published", "html": "<markup>", "css": "<css>" } ]

        Page "html" is composed from the component vocabulary: each block is a
        `data-pb-block="<key>"` element with inline styles. Pages may bind to app
        state with DECLARATIVE Alpine directives only — x-text, x-show, x-model,
        x-for — referencing `$store.app.<stateKey>`. A data table uses
        `x-data="pbTable('<collection key>')"`. NEVER emit executable directives
        (@click, x-on:*, x-init) in page output.
        TXT;
    }

    private function example(): string
    {
        return <<<'TXT'
        ### Example plan (compact)

        {"collections":[{"key":"tasks","name":"Tasks","has_timestamps":true,"has_soft_deletes":false,"fields":[{"key":"title","label":"Title","type":"string","options":{"required":true}},{"key":"done","label":"Done","type":"boolean","options":{"default":false}}],"seed":[{"title":"First task","done":false}]}],"states":[{"key":"filter","type":"string","value":"all"}],"pages":[{"slug":"home","title":"Home","status":"published","html":"<section data-pb-block=\"hero\" class=\"pb-hero\" style=\"padding:4rem 1.5rem;text-align:center;\"><h1 class=\"pb-hero__title\">My tasks</h1></section><table data-pb-block=\"data_table\" class=\"pb-data-table\" x-data=\"pbTable('tasks')\"><tbody><template x-for=\"row in rows\" :key=\"row.id\"><tr><td x-text=\"row.title\"></td></tr></template></tbody></table>","css":""}]}
        TXT;
    }

    private function componentCatalog(): string
    {
        /** @var array<string,list<SectionBlock>> $byCategory */
        $byCategory = [];
        foreach (BlockVocabulary::all() as $block) {
            $byCategory[$block->category][] = $block;
        }

        $lines = [
            '## Component catalog',
            '',
            'Build page "html" from these blocks only. Use each block as a',
            '`data-pb-block="<key>"` element. The "Data" category is data-bound',
            '(reads records / state); "Forms" blocks build input forms.',
            '',
        ];

        foreach ($byCategory as $category => $blocks) {
            $lines[] = "### {$category}";
            foreach ($blocks as $block) {
                $desc = $block->description !== '' ? ' — '.$block->description : '';
                $lines[] = "- `{$block->key}` ({$block->label}){$desc}";
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function fieldTypes(): string
    {
        $lines = [
            '## Collection field types',
            '',
            'A collection field\'s "type" must be one of:',
            '',
        ];

        foreach (FieldType::cases() as $type) {
            $lines[] = "- `{$type->value}` — {$type->label()}";
        }

        $lines[] = '';
        $lines[] = 'For `relation` set `options.relation_model` to the related collection key.';
        $lines[] = 'For `select` set `options.choices` to the allowed string values.';

        return implode("\n", $lines);
    }

    private function nodeTypes(): string
    {
        $lines = [
            '## Flow node types',
            '',
            'A flow node\'s "type" must be one of the registered node types below.',
            'Each node hands off via "next" (or "next_true"/"next_false" for a',
            'condition). The flow\'s "start" names the first node id.',
            '',
        ];

        foreach ($this->nodeTypeList() as $type) {
            $hint = self::NODE_HINTS[$type] ?? 'config: { ... } — see the builder docs.';
            $lines[] = "- `{$type}` — {$hint}";
        }

        return implode("\n", $lines);
    }

    /**
     * The registered node types, in a deterministic order. Resolved from the
     * live NodeRegistry when available (so it reflects what the engine actually
     * runs) and sorted for stability; falls back to the known hint keys when the
     * registry can't be resolved (e.g. prompt generated outside a booted app).
     *
     * @return list<string>
     */
    private function nodeTypeList(): array
    {
        $types = array_keys(self::NODE_HINTS);

        if (function_exists('app')) {
            try {
                $registry = app(NodeRegistry::class);
                $registered = $registry->types();
                if ($registered !== []) {
                    $types = $registered;
                }
            } catch (\Throwable) {
                // Outside a booted container — fall back to the hint keys.
            }
        }

        $types = array_values(array_unique($types));
        sort($types);

        return $types;
    }

    private function rules(): string
    {
        return <<<'TXT'
        ## Rules

        - Output ONLY the JSON build plan. No prose, markdown or code fences.
        - Emit only keys from the catalogs above (component keys, field types, node types).
        - Keep all keys and slugs lowercase; use snake_case for collection/field/state keys and kebab-case for slugs.
        - Reference only collections and states that already exist or are defined in the same plan.
        - Pages use `data-pb-block` blocks with inline styles and DECLARATIVE Alpine bindings (x-text/x-show/x-model/x-for) over `$store.app.<state>` only — never @click/x-on/x-init.
        - Data tables bind with `x-data="pbTable('<collection key>')"`.
        - Prefer the smallest plan that satisfies the request.
        TXT;
    }
}
