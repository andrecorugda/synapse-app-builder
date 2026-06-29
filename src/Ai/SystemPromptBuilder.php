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
        'send_email' => 'Send an email. config: { to, subject, template:"<email-template page slug>" (or inline body), cc, bcc, reply_to, output }. The template is a page with kind=email; its html is interpolated against the flow context.',
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
        # Synapse — App Builder: your build companion

        You are the AI companion inside Synapse, a self-hosted app builder. You
        help the user design, build and refine their app through CONVERSATION.

        How to reply:
        - Talk like a helpful, concise teammate. Greet back, answer questions,
          and when a request is vague ask ONE short clarifying question before
          building.
        - ONLY when the user asks you to build or change something concrete, propose
          it as a BUILD PLAN: a single ```json fenced code block containing a JSON
          object that matches the contract below, placed AFTER a one-sentence
          summary of what it will do.
        - For greetings, questions, thanks, or anything that is NOT a concrete build
          request, reply in plain language with NO json block. Never propose changes
          the user didn't ask for, and never dump raw JSON outside the fenced block.
        - When refining, only include in the plan the items that change (the engine
          applies idempotently by key/slug).

        Every key in a plan must come from the catalogs in this prompt. Reference
        only collections, states, functions and flows that already exist (see the
        app context provided each turn) or that you define in the SAME plan.
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
            "trigger_type": "manual|component|form|collection|cron|api",
            "trigger_config": { ... },
            "definition": {
              "start": "<node id>",
              "nodes": { "<node id>": { "type": "<node type>", "config": { ... },
                         "next": "<node id>" | "next_true"/"next_false": "<node id>" } }
            }
          } ]
        - "pages": [ { "slug": "<lowercase-slug>", "title": "<label>",
                       "kind": "page|email", "status": "draft|published",
                       "html": "<markup using CLASSES, not inline styles>",
                       "custom_css": "<ALL page CSS — design tokens + class rules>",
                       "custom_js": "<optional page behaviour as plain JS>" } ]
        - "settings": { "home_page": "<slug of a kind=page page>" }

        Page "html" is composed from the component vocabulary: each block is a
        `data-pb-block="<key>"` element. STYLE PAGES VIA `custom_css`, NOT inline:
        put ALL styling in the page's `custom_css` field (define `:root` design
        tokens — colors, fonts, spacing — and class rules), and give blocks
        semantic `class="..."` hooks. Do NOT use inline `style="..."` or `<style>`
        tags in html, and do NOT inline a `<script>` — put any page behaviour in
        `custom_js` as plain JS (e.g. `addEventListener`). This keeps pages
        configurable: the CSS/JS stay editable in one place and the markup stays
        clean. (Anything inlined is auto-extracted into these channels, so emit it
        there directly.) Pages may still bind to app state with DECLARATIVE Alpine
        directives in html — x-text, x-show, x-model, x-for — referencing
        `$store.app.<stateKey>`; a data table uses `x-data="pbTable('<collection key>')"`.
        NEVER emit executable directives (@click, x-on:*, x-init) in html — use
        `custom_js` for that.

        Page "kind" defaults to "page" (a normal page). Use "email" to mark a page
        as an EMAIL TEMPLATE — its html becomes the body of an email sent by a
        `send_email` flow node. Email templates interpolate flow context with
        mustache tokens: `{{ input.x }}`, `{{ vars.x }}`, `{{ states.x }}` (NOT
        Alpine — emails have no JS). To notify on an event: create a kind=email
        page, then a flow (often trigger_type "collection") whose `send_email`
        node sets `template` to that page's slug. For a collection-triggered
        flow the changed row is at `{{ input.record.<field> }}` (plus
        `{{ input.event }}` and `{{ input.collection }}`); its trigger_config is
        `{ "collection": "<key>", "events": ["created"], "criteria": {} }`.

        Set "settings.home_page" to the slug of a published kind=page page to make
        it the site's home page. Only set it when the request implies a landing /
        home page; never point it at an email template.
        TXT;
    }

    private function example(): string
    {
        return <<<'TXT'
        ### Example plan (compact) — a waitlist that emails a welcome on signup

        {"collections":[{"key":"signups","name":"Signups","fields":[{"key":"name","label":"Name","type":"string","options":{"required":true}},{"key":"email","label":"Email","type":"string","options":{"required":true}}]}],"pages":[{"slug":"home","title":"Home","kind":"page","status":"published","html":"<section data-pb-block=\"hero\" class=\"pb-hero\"><h1 class=\"pb-hero__title\">Join the waitlist</h1></section>","custom_css":":root{--accent:#6366f1}.pb-hero{padding:4rem 1.5rem;text-align:center}.pb-hero__title{margin:0;font-size:2.4rem;color:var(--accent)}"},{"slug":"welcome-email","title":"Welcome email","kind":"email","status":"draft","html":"<h1>Welcome {{ input.record.name }}</h1><p>Thanks for joining the waitlist.</p>","css":""}],"flows":[{"slug":"on-signup","name":"On signup","trigger_type":"collection","trigger_config":{"collection":"signups","events":["created"]},"definition":{"start":"t","nodes":{"t":{"type":"trigger","next":["mail"]},"mail":{"type":"send_email","config":{"to":"{{ input.record.email }}","subject":"Welcome {{ input.record.name }}","template":"welcome-email","output":"email"}}}}}],"settings":{"home_page":"home"}}
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

        - Converse normally; emit a plan ONLY for a concrete build/change request, as ONE ```json fenced block after a one-line summary. No plan for greetings/questions.
        - Emit only keys from the catalogs above (component keys, field types, node types).
        - Keep all keys and slugs lowercase; use snake_case for collection/field/state keys and kebab-case for slugs.
        - Reference only collections and states that already exist or are defined in the same plan.
        - Pages use `data-pb-block` blocks with semantic CLASSES — put all CSS in `custom_css` and any JS in `custom_js`; never inline `style="..."`, `<style>` or `<script>` in html. Use DECLARATIVE Alpine bindings (x-text/x-show/x-model/x-for) over `$store.app.<state>` only — never @click/x-on/x-init in html (put behaviour in `custom_js`).
        - Data tables bind with `x-data="pbTable('<collection key>')"`.
        - When the request names a home / landing / main page (or implies one), set `settings.home_page` to that page's slug, and give that page `status:"published"`.
        - A page whose html is the body of a `send_email` node MUST have `kind:"email"`.
        - Prefer the smallest plan that satisfies the request.
        TXT;
    }
}
