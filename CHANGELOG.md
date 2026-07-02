# Changelog

All notable changes to `andrecorugda/synapse` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **HTTP Request node SSRF guard closes host-spelling bypasses.** The guard
  resolved hosts with `dns_get_record()`, which ignores `/etc/hosts` and doesn't
  understand every spelling cURL dials — so `localhost`, an IPv6 loopback
  literal `[::1]`, and a decimal/dotless IPv4 (`2130706433` = `127.0.0.1`) all
  slipped through to loopback. Hosts are now normalized (brackets stripped,
  decimal IPs expanded), `localhost` is refused outright, and name resolution
  also goes through the libc resolver (`gethostbynamel`, matching cURL and
  consulting `/etc/hosts`) in addition to DNS, so a name mapped to an internal
  address is caught.

### Fixed

- **Boolean variables parse textual values by meaning.** A `boolean` variable
  (or Set Variable node) set to the string `"false"` stored `true` — a plain
  truthiness test treats any non-empty string as true. `"false"`/`"no"`/`"off"`/
  `"0"` now store `false`; `"true"`/`"yes"`/`"on"`/`"1"` store `true`.
- **A handled transaction rollback records as `ok`, not `ok`-with-an-error.** A
  transaction that rolled back and followed its `rolled_back` branch left the
  run-level error populated, so telemetry showed a successful run carrying a
  stray error message. The reason is still exposed to the branch as
  `vars.error`; the run-level error is cleared. (A rollback with no
  `rolled_back` branch still fails the run, with the original message.)
- **HTTP Request node has a request timeout.** Added a configurable per-request
  timeout (`flow.http_timeout`, default 15s) so a slow/hung host can't tie up a
  worker indefinitely.
- **Editing a collection field's type now ALTERs the column.** The synchronizer
  only ever *added* columns, so changing an existing field's type (e.g.
  string → number) in the admin left the physical column at its old type — the
  edit silently didn't take. Sync now ALTERs a column when the field's storage
  category changes; a no-op edit (relabel, length tweak, text↔json) issues no
  needless ALTER. (Renames and index changes remain create/destructive-sync
  concerns.)
- **Set Variable applies the chosen type everywhere.** Only the persisted State
  was cast; the live `setState` action pushed to the page and the downstream
  `output` var kept the raw interpolated string — so a `number`/`boolean` State
  displayed and read downstream as text. The value is now cast once, up-front.
- **A loop/transaction that overruns the step budget now fails, not silently
  half-commits.** When a Loop's cumulative steps crossed `flow.max_steps`
  mid-iteration the walk exited silently without flagging failure — the Loop
  kept counting un-run iterations and reported the full count, and a wrapping
  Transaction *committed* the partial writes and followed the `committed`
  branch. Budget exhaustion is now a run failure (a Transaction rolls back). The
  default `max_steps` is also raised (1000 → 100000) so a Loop at its
  10k-iteration ceiling with a small body no longer trips the cap.
- **An unknown node type fails the run instead of silently truncating it.** A
  node whose `type` had no handler was logged and skipped, its `next` never
  enqueued — every downstream node vanished and the run was recorded `ok`. A
  typo'd/removed node type is now a run failure.
- **A flow that calls itself is caught at the first level.** The running flow's
  slug was not seeded onto the call stack, so a top-level `call_flow` back to
  the same flow ran its whole body one extra pass (side effects fired twice)
  before the cycle guard tripped one level deeper. The entry slug is now seeded.
- **Boolean state watcher fires when a flag turns off.** A state watcher with
  `to: false` (stored by the form as the string `"false"`) never fired: PHP's
  loose `==` treats the non-empty string `"false"` as truthy, so `false ==
  "false"` was false. `from`/`to` now compare on boolean meaning when the state
  value is a real boolean.
- **A bad schedule timezone no longer aborts the whole tick.** `setTimezone()`
  ran outside the cron guard, so one schedule with a typo'd IANA name threw and
  every schedule after it silently never ran. A bad timezone is now logged and
  treated as not-due, exactly like a bad cron expression.
- **Export/import carries partials.** `export-app` omitted the `partials`
  section, so a re-imported site lost its shared chrome — the nav/header/footer
  embedded via `data-pb-partial` vanished (a flow-opened modal showing a partial
  went blank; every page's nav disappeared). Partials now round-trip.
- **Duplicate `unique` value → a clean 422, not a 500.** A `unique` field was
  enforced only by the DB index, so a duplicate write threw a raw query
  exception (HTTP 500, leaking DB details under debug) instead of a field-level
  "already taken" validation error. A `unique` rule is now added on validate
  (ignoring the current row on update).
- **Malformed `between` filter no longer 500s.** `filter[x][between]=5` (a
  single bound) crashed with a bound-count mismatch; it's now ignored (a valid
  two-value `between` still filters).
- **Maintenance-mode admin bypass works.** An admin end-user could not preview
  the live site during maintenance — the bypass checked a non-existent
  `user.is_admin` attribute instead of the role flag (`isAdmin()`), so admins
  got the 503 like everyone else. Now admins bypass; non-admins still get the
  maintenance page.

### AI

- **Prompt/validator accuracy.** The generator now gets a real `call_flow` node
  hint (was generic); the Result-node hint no longer advertises `setState`/
  `setStates`/`setText` (which the node drops — state writes go through Set
  Variable); the offline validator's fallback node list now includes
  `loop`/`transaction`/`call_flow` (its own canonical example uses them); and
  the example email page uses `custom_css` (the real channel) not `css`.

### Fixed

- **Reference fields get their pickers inside transaction/loop bodies.** A
  node used as a body step rendered `integration` / `credential` / email
  `template` / `function` / `flow` as free-typed text (the top-level canvas
  showed dropdowns); body steps now render the same dropdowns from the live
  lists.
- **`context_menu` content is now editable in the editor** — it was missing from
  the dialog/disclosure reveal set, so its menu items stayed hidden in the canvas.
- **KPI / chart no longer show a fake "0" on a failed data load.** A denied
  (403) or failed aggregate now renders "—" (KPI) or a "Could not load … you may
  not have access" note (chart) instead of a misleading zero / empty canvas —
  matching how `data_table` already surfaces the error.
- **Inventory demo "Sign out" works (was a 419).** The demo shipped a raw
  `<form action="/pb-logout">` with no CSRF token → every logout hit 419 and the
  user was stranded, still signed in. It now uses the token-aware
  `data-pb-logout` runtime control (the built-in no-code logout mechanism).
- **The Embed block works again.** Its `<iframe>` was stripped on every save
  (the HTML sanitizer removed all iframes, and it runs on owner saves too), so
  the block never rendered. The sanitizer now keeps the **Embed block's** iframe
  — hardened: `srcdoc` dropped, a `sandbox` forced (permits YouTube/Vimeo/Maps,
  blocks top-window navigation), and the embed URL scheme-checked — while any
  stray/AI-injected iframe is still removed.
- **Flow Result-action builder now uses the real catalog.** The builder fell
  back to a stale inline list because `window.__pbActionCatalog` was never
  injected — so it offered `setState`/`setText` actions the node silently
  discards, and hid the real catalog's Modal **Partial** picker, Redirect
  **new-tab** option, and target-field help. The canonical `ResultActionCatalog`
  is now injected and used (and the dead inline fallback no longer lists the
  discarded types).
- **`data_table` and `list` now render when built as prescribed.** A bare
  `<div data-pb-block="data_table" data-pb-collection="…">` (the form the docs +
  AI emit) was never expanded — those blocks are category *Data*, but the
  renderer only expanded *Interactive* blocks + `kpi`/`chart` — so they got no
  `x-data`, never fetched, and showed an empty div. Both are now expanded;
  `data_table` binds its collection, and a bare `list` rebinds `x-for` to its
  `data-pb-state` key. (The single most user-visible data defect.)

### Flows

- **A flow can open the modal the author designed** — the Result `modal` action
  now flips the designed modal's Alpine `open` state (falling back to
  class/display for custom markup), so targeting a Modal block by `#id`
  opens/closes it exactly like a click; content swaps go into the dialog body,
  never the Alpine root. Modal (and Drawer) blocks gained an **ID** trait for
  no-code targeting.
- **Designed content in a flow's modal** — the Result `modal` action can pick a
  **Partial** to show as the dialog body; the partial's (sanitized) html is
  resolved and interpolated against the flow context. So "show a dialog with
  something I designed" is now a no-code path (design a Partial → point the flow
  at it).

### Editor

- **Design inside dialog / disclosure blocks** — the editor canvas now reveals
  the content of `modal`, `drawer`, `tabs`, `accordion`, `dropdown_menu` and
  `tooltip` blocks (previously hidden by `x-cloak`/`x-show` because Alpine is off
  in the canvas), so authors can actually edit and fill what's inside them. A
  dashed marker shows they're open only for editing; the published page is
  unchanged (closed by default, opens on interaction).

## [1.2.0] - 2026-07-02

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
