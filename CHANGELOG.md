# Changelog

All notable changes to `andrecorugda/synapse` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Editor

- **Extensible component settings** — a registered/premium block
  (`SectionBlock`) can declare its own `settings` (`ComponentSetting[]`: text /
  number / checkbox / select), mirroring the flow-node config-input pattern. The
  editor renders each as a trait on the selected component, writing a plain
  attribute the block's template / `custom_js` reads. Built-ins are unchanged
  (empty settings by default).
- **Input constraint traits** — `<input>` blocks expose `min` / `max` / `step` /
  `pattern` / `maxlength` under Validation (native HTML attributes; persist to
  the published page).

### Automation

- **Legacy cron flows surface as Schedules** — a back-fill migration creates an
  **inactive** Schedule row (placeholder cadence, "(review cadence)" name) for
  each `trigger_type='cron'` flow, so cron automations move to the first-class
  Schedules model (per-flow cron + function targets) without silently changing
  timing. The `run-cron-flows` command still works; its description notes the
  deprecation.

### State & Watchers

- **AI authors watchers directly** — the build-plan contract now teaches the
  `watchers` section, so a generated app reacts to record/state changes via a
  watcher targeting a reusable flow instead of embedding `trigger_config`
  (legacy plans still work via materialization).
- **Test-fire + Runs for watchers** — the watcher edit page has a **Test fire**
  action (runs the target once with a sample payload, conditions bypassed) and a
  **Runs** tab listing the flow runs it caused. Flow runs now carry a nullable
  `watcher_id` (new `flow_runs` column) so a run's provenance is queryable;
  browser-side (client) state watchers are hard-locked to flow targets on save.
- **"Only when these fields changed"** — a collection *update* watcher can list
  fields (`config.changed`) and fire only when one of them actually changed
  (old ≠ new); a no-op for created/deleted. Combines with criteria.
- **Browser-side state watchers** — a state watcher can watch **live page state** (`$store.app`) as the visitor interacts, like a JS framework watcher: pick *Browser* under "Watch where". Rendered pages install debounced reactive effects that fire the target flow on change (with the same path / from→to / operator conditions, evaluated client-side) and apply the returned actions; a loop guard stops a flow that rewrites its own watched key from re-triggering itself. Server dispatch skips browser-side watchers so a persisted write never double-fires.
- **Watcher form UX** — the form now reads as a sentence in three sections (*Watch* → *Only when (optional)* → *Then run*); for Object states the watched value is picked from the shape's **flattened dotted paths** (no more free-typed path), and collection criteria pick from the collection's fields.
- **`trigger_type` is now an advisory label** — flows are reusable graphs; collection/state triggering is managed by Watchers and scheduling by Schedules. The Flow form drops the embedded collection trigger config. AI-generated collection flows keep working: applying a build plan materializes the equivalent watchers automatically.
- **Watchers travel with the app** — app export/import round-trips watchers (the build-plan contract gained a `watchers` section); the AI sees existing watchers as app context. The Watchers menu icon is now an eye.

## [1.1.0] - 2026-07-02

### Flow editor & engine

- Transaction and Loop node bodies are now edited as an ordered, sortable **step list** (Function / Flow / Loop / node) with per-step kebab menu for reorder and delete; compiles to/from the engine's `{start, nodes}` losslessly.
- New **`call_flow`** node: runs another saved flow's definition as a sub-step, sharing the caller's `vars`/`input` context; cycle-guarded (direct and transitive cycles blocked); counts against the shared step budget.
- **Single entry point** enforced: every flow has exactly one Trigger node (badged START); the editor blocks adding a second.
- **Non-overlapping auto-layout** for AI-generated and programmatic flows.
- **Zoom controls** in the canvas toolbar: zoom out / reset / zoom in / fit-to-screen (now rendered as Phosphor duotone icons).
- **Colour-coded flow nodes**: each node type has its own coloured Phosphor duotone icon on the node card, in the "+ Add node" drawer, and in the transaction/loop step picker.
- Condition/Transaction outputs are labelled **True / False** (was `output_1 = true`) with **green / red colour-coded branch ports** on the canvas.
- **Result** node now uses a low-code actions builder: a type dropdown (Notify / Alert / Modal / Redirect / Set state / Set HTML / Set text / Add class / Remove class / Log out) with conditional field visibility per type.
- Editing an AI-generated flow round-trips losslessly through the step-list editor.
- **Component settings** grouped into collapsible categories; form controls expose Placeholder / Field name / Required / Input type traits.

### State & Watchers

- **Object state type**: a global variable can be typed as an **Object** with a nestable, typed **shape** (string / number / boolean / nested object / reference to another state), edited with a recursive builder; the composed default is shown as JSON alongside.
- **Path-aware binding**: a two-step *state → dotted path* picker in the flow and function editors and in page-component data bindings — bind to `state.address.city`, not only the whole value.
- **Watchers** — a first-class reactive trigger binding **one source event → one target** (flow or function), decoupled from the flow graph, managed under **Automation → Watchers**:
  - **Collection watchers** fire on a record `created` / `updated` / `deleted` with optional field **criteria**; each event can target a **different** flow (previously a single graph handled every event). Existing `trigger_type=collection` flows are auto-migrated to watchers.
  - **State watchers** fire when a global variable changes (via `VariableStore::set`, so `set_variable` flow steps are covered), with an optional Object **sub-path**, **from → to** transition, and operator conditions.
  - Re-entrancy depth-guarded; each run stamps last-fired / status telemetry; flow and function targets both supported.

### Data

- List endpoint supports `?expand=*` (or `?expand=field1,field2`): resolves belongs-to relations so the response includes the related record's `name` instead of the raw foreign-key id.
- Auto `data_table` uses `?expand=*` by default — relation columns show names, never blank ids.
- Per-row **Edit** (fills form, switches POST → PUT) and **Delete** on management-page data tables.

### Pages & components

- **Management page** pattern: an Add form (`data-pb-record`) + auto-refreshing data table + per-row Edit/Delete — the canonical way to build a CRUD UI.
- `data_table` shell auto-renders columns from fetched data (relation names resolved); KPI/chart widgets render from a configured-but-empty wrapper.
- **Image fields** — file input that uploads on select via the `/pb-upload` endpoint and submits the returned URL.
- **`POST /pb-upload`** public image upload: authenticated by default (anonymous opt-in via config), image-only (jpeg/jpg/png/gif/webp), size-capped, rate-limited, safe filenames, returns `{url}`.
- **Colour-coded block palette**: every block ships a coloured Phosphor duotone icon (per category) in the GrapesJS block manager.

### Fixed

- **Per-page `custom_js` now loads before Alpine** (Alpine is deferred). Previously custom JS was emitted after Alpine had already auto-started, so a component factory defined there (`window.myApp = () => ({…})` used in `x-data="myApp()"`) did not exist when Alpine evaluated the first `x-data` — the page rendered inert (empty tables, dead buttons). Regression-tested.
- **Inventory demo** (`ai-page-builder:install-demo`) authored within the HTML sanitizer's model: component defined in `custom_js`, `init()` in place of `x-init`, and a `data-act` delegated click handler in place of `@click` — so it loads its 8 products, computes KPIs, filters/searches and opens the add-product modal.
- **Private flows are triggerable from the app** (same-origin); public flows remain open for external API access. `set_state` now updates the page's live store immediately.
- **Component-triggered flows run from a page** — correct state binding, plus a click guard that ignores clicks on inner interactive elements.
- **Result node** alert/modal now render, toast levels apply, redirect can open a new tab; the log-out action is scoped to the page-builder guard.
- **"Visible to roles"** renders as real checkboxes instead of stretched full-width bars.
- **Password reset** no longer flashes an "expired link" error on a successful reset.

### Configuration

- `ai-page-builder.filament.max_content_width` (default `'full'`) — widens every Synapse admin page; any Filament width value accepted; `null` inherits the host default.
- `ai-page-builder.uploads.allow_anonymous` (default `false`) — opt-in anonymous uploads to `/pb-upload`.
- `ai-page-builder.uploads.max_kb` (default `5120`) — size cap for `/pb-upload`.

### AI

- Generator composes reusable functions/flows rather than repeating logic (conditional factoring; no over-factoring of one-off steps).
- Deterministic **generation quality harness** (`tests/Feature/GenerationQualityTest.php`) asserts a generated app's full stack: pages render, data binds, checkout runs, relations resolve.

## [1.0.2] - 2026-06-29

### Fixed
- Editor canvas now faithfully renders hand-authored Alpine pages (including the bundled Nimbus and Synapse demos). An Alpine attribute bridge stops GrapesJS throwing on `@click`/`:class` (which previously aborted the import and left the canvas white), and page-frame CSS — design tokens, `@font-face`, base background — is preserved so token-driven and dark-themed pages render correctly instead of appearing white. Saving is lossless: interactivity (`@click`) and design tokens survive the editor round-trip.

### Changed
- Homepage now points to the official site (https://synapse-app.site); added `support` docs/issues/source links and Website + Documentation links in the README.

## [1.0.1] - 2026-06-29

### Changed
- Tightened the package description (concise, leads with the n8n-style flow engine) and added the Filament-directory submission art (16:9 cover + thumbnail).

## [1.0.0] - 2026-06-29

First public release — **Synapse, the AI app builder** for Laravel + Filament.

### Added
- **Pages & components** — GrapesJS visual builder for Filament 4/5: `Page` model + `PageResource`, a `GrapesJsField`, a block vocabulary (sections + primitives + a UI kit: cards/modals/drawers/tabs/forms/**data tables**), per-page custom CSS & JS, SEO meta, duplicate, a cached front-end render route (`/{prefix}/{slug}`, overridable), media library, and a configurable **home page** (served at the prefix root, opt-in at the site root).
- **Collections (data layer)** — define models → real `pb_<key>` tables (Directus-style) via `SchemaSynchronizer`; typed `FieldType`s; `RecordQuery` (filter/sort/search/paginate + validation) behind an auto **REST API** (`/api/pb/{model}`), the flow Collection node, and a records browser.
- **Flows (automation engine)** — visual Drawflow canvas; `FlowRunner`/`NodeRegistry`; nodes: trigger, ai_invoke, http_request, function, record (CRUD), condition, set_variable, **send_email**, result; triggers: component DOM events, collection events (create/update/delete + criteria), cron, and a public rate-limited run endpoint; `FlowRun` telemetry.
- **Functions & States** — reusable logic (expression / callable / PHP, gated) edited in Ace; a persistent typed **State** store seeded into a reactive Alpine `$store.app` with declarative data binding and `setState` flow actions.
- **End-user auth** — the built app's own `pb_users`/`pb_roles`/`pb_permissions`, a session guard, a static login, per-page `requires_auth` gating; an **opt-in permission engine** (per-collection CRUD, **row-level rules** with `$CURRENT_USER`) enforced at the REST API; component visibility by login/role. Entirely optional.
- **Email** — an isolated SMTP transport configured in Settings (encrypted password) + a `send_email` flow node that uses any `kind=email` page as an interpolated template, with a "send test" action.
- **AI app generation** — requires the AI OpenRouter Gateway. A code-generated system prompt + a validated **Build Plan** contract (collections/states/functions/flows/pages/settings) applied idempotently by `BuildPlanApplier`; a **Build with AI** page and a **dockable, thread-retained floating chat** to generate and iteratively refine apps; AI HTML is sanitized; applying is human-in-the-loop.
- Documentation (`docs/`), marketing cover, and Packagist/Filament submission metadata.
