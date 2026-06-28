# AI

[← Docs index](README.md)

The AI layer is **optional and additive** — the builder is fully usable by hand. When enabled, you describe an app (or a change) in plain language; the model returns a structured, validated **Build Plan**; you review it; and an idempotent applier writes it through the same services everything else uses. The AI never writes files or runs code.

## Requirements

AI generation requires the **AI OpenRouter Gateway** (`andrecorugda/ai-openrouter-gateway`) — it carries the API key, the model, metering/cost caps and conversation threads. Install it and set a key:

```bash
composer require andrecorugda/ai-openrouter-gateway
# .env: OPENROUTER_INTEGRATION_KEY=sk-or-...
```

The package talks to it through the [`AiInvoker`](extending.md#the-aiinvoker-contract) abstraction (`GatewayAiInvoker` by default), so the rest of the package stays gateway-optional and the AI services stay unit-testable. `AppBuilderService::available()` is false without it, and the *Build with AI* page / chat degrade gracefully ("you can still build manually").

The `ai.driver` config is `auto` by default: use the gateway when installed, else the direct OpenRouter driver, else a null driver (manual editing still works). Force it with `gateway` or `openrouter`.

## The Build Plan contract

A Build Plan is a single JSON object with six **optional** sections (`src/Ai/BuildPlan.php` is the typed value object; the model is taught the shape by `SystemPromptBuilder`). Include only what the request needs.

| Section | Item shape (key fields) |
|---|---|
| `collections` | `{ key, name, has_timestamps?, has_soft_deletes?, fields: [{ key, label, type, options }], seed?: [{...}] }` |
| `states` | `{ key, type?: string\|number\|boolean\|json, value }` |
| `functions` | `{ slug, name, runtime: expression\|callable\|php, body }` |
| `flows` | `{ slug, name, trigger_type, trigger_config, definition: { start, nodes } }` |
| `pages` | `{ slug, title, kind: page\|email, status: draft\|published, html, css }` |
| `settings` | `{ home_page: "<slug of a kind=page page>" }` |

Field `options` carry `required`, `unique`, `default`, `length`, `choices` (for `select`), `relation_model` (for `relation`). Pages compose their `html` from the [block vocabulary](pages-and-components.md#the-block-vocabulary) (`data-pb-block="<key>"` elements) and bind to state with **declarative** Alpine only (`x-text`/`x-show`/`x-model`/`x-for` over `$store.app.<state>`; a data table uses `x-data="pbTable('<collection>')"`). The page `kind` defaults to `page`; `email` marks an [email template](email.md) whose HTML is mustache-interpolated by a `send_email` node. `settings.home_page` makes a published `kind=page` page the site home.

### Validation

`src/Ai/BuildPlanValidator.php` runs **before** the applier touches the DB, returning a flat list of human-readable errors (empty = valid). It blocks structural breakage — invalid slugs (`^[a-z][a-z0-9_-]*$`), duplicate keys, unknown field types (checked against `FieldType::cases()`), unknown flow trigger types and **unregistered node types** (resolved from the live `NodeRegistry`), a `home_page` that points at an email template — while treating advisory issues (an unknown `data-pb-block` key, a `home_page` slug not created in the same plan) as warnings.

## Build with AI

The **Build with AI** admin page (`src/Filament/Pages/BuildWithAi.php`) is the describe → review → apply surface:

1. **Describe** — a `brief` (what you want) and optional `business` (brand/guidelines).
2. **Generate plan** — calls `AppBuilderService::generate($brief, $business)`, which sends the seeded `app_builder` system prompt + the live app context + your brief through the gateway, then extracts and validates the plan. The reviewable plan, its validation errors and the raw model reply are shown.
3. **Apply plan** — visible once a plan exists, **disabled while there are validation errors**, and requires confirmation. Calls `BuildPlanApplier::apply($plan)` and reports what was created (collections, states, functions, flows, pages, settings).

## The floating chat

A dockable AI companion is injected on every admin page (the ✦ orb; `src/Filament/views/.../ai-chat.blade.php` via a Filament render hook), backed by `AiChatController`:

- `POST /ai-page-builder/ai-chat` (**send**) — threads the **whole conversation** through `AppBuilderService::generate()`, so the model retains context and can iteratively **refine** what you've built. Returns a friendly one-line summary ("Proposed: 2 collections, 1 page…"), the raw reply (for thread continuity), the plan, and any validation errors.
- `POST /ai-page-builder/ai-chat/apply` (**apply**) — commits the proposed plan via `BuildPlanApplier` (human-in-the-loop: generation and application are separate steps).

Because the chat is thread-aware and context-aware, you can build on one page and refine on another — "refine from anywhere."

## Idempotent apply

`src/Ai/BuildPlanApplier.php` is the only writer in the AI layer, and it drives the same services the REST API / Filament use — so AI-built artifacts behave like hand-built ones. It:

- **Upserts by key/slug** — collections by `key`, states by `key`, functions/flows/pages by `slug`. **Refining is just re-applying** a plan that references existing items: the second apply updates rather than duplicates.
- **Is best-effort, not transactional** — creating a collection runs DDL (`Schema::create`), which auto-commits on MySQL and would end any wrapping transaction, so each artifact applies independently; per-item failures are reported and re-applying completes anything missed.
- **Sanitizes page HTML** — every page's `html` is run through `HtmlSanitizer` before storage.
- **Seeds records** through `RecordQuery` (validated + column-mapped like any write).
- **Supports `dryRun`** — reports what *would* be created without writing.

## The code-generated system prompt

`src/Ai/SystemPromptBuilder::build()` generates the engine system prompt **from the package's own sources of truth** — `BlockVocabulary` (component keys), `FieldType::cases()` (field types) and the registered `NodeRegistry` nodes (with one-line config hints per node). It is fully deterministic (stable ordering, no randomness), so it can be seeded as the gateway integration's system prompt and diffed across versions — and it can never drift from what the engine actually supports as blocks/types/nodes are added.

`src/Ai/AppContextBuilder` adds the **dynamic** per-request context: this app's existing collections, states, pages, functions and flows (every read guarded so missing tables degrade to empty), so the model extends reality and references real keys instead of inventing them.

### Self-healing integration

On boot, when the gateway is present, the package seeds the `app_builder` integration with the generated prompt (`AppBuilderIntegrationSeeder`). It is idempotent and **self-healing** — re-seeded if deleted so the feature can't be broken by removing it — and it never clobbers a tuned prompt version. (`AI_PAGE_BUILDER_AI_AUTO_SEED` controls this; the manual command is `php artisan ai-page-builder:seed-integration`.)

## Safety

- **HTML sanitization** — AI output is untrusted, so `src/Ai/HtmlSanitizer.php` strips `<script>`, `<iframe>`, `<object>`/`<embed>`, inline `on*` handlers, `javascript:`/`vbscript:`/`data:text/html` URLs, and **executable** Alpine (`@*`, `x-on:*`, `x-init`, `x-effect`, `x-html`). It **keeps** declarative Alpine (`x-data`, `x-show`, `x-text`, `x-model`, `x-for`, `x-bind:`/`:`, `x-cloak`, `x-transition*`), `data-pb-*` and safe URLs (including `data:image/*`). Owner-authored blocks are trusted and bypass it — only AI page HTML is routed through `sanitize()`.
- **Human-in-the-loop** — generation never auto-applies. You review a plan (and its validation errors) and explicitly apply.
- **Metered & cost-capped** — invocation goes through the gateway, which enforces rate/cost limits.

## Example plan

The prompt's own compact example — a waitlist that emails a welcome on signup:

```json
{
  "collections": [ { "key": "signups", "name": "Signups", "fields": [
    { "key": "name",  "label": "Name",  "type": "string", "options": { "required": true } },
    { "key": "email", "label": "Email", "type": "string", "options": { "required": true } }
  ] } ],
  "pages": [
    { "slug": "home", "title": "Home", "kind": "page", "status": "published",
      "html": "<section data-pb-block=\"hero\" class=\"pb-hero\" style=\"padding:4rem 1.5rem;text-align:center;\"><h1 class=\"pb-hero__title\">Join the waitlist</h1></section>", "css": "" },
    { "slug": "welcome-email", "title": "Welcome email", "kind": "email", "status": "draft",
      "html": "<h1>Welcome {{ input.record.name }}</h1><p>Thanks for joining the waitlist.</p>", "css": "" }
  ],
  "flows": [ { "slug": "on-signup", "name": "On signup", "trigger_type": "collection",
    "trigger_config": { "collection": "signups", "events": ["created"] },
    "definition": { "start": "t", "nodes": {
      "t":    { "type": "trigger", "next": ["mail"] },
      "mail": { "type": "send_email", "config": { "to": "{{ input.record.email }}", "subject": "Welcome {{ input.record.name }}", "template": "welcome-email", "output": "email" } }
    } } } ],
  "settings": { "home_page": "home" }
}
```

Next: [Configuration](configuration.md).
