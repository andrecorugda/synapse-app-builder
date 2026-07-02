# Pages & components

[← Docs index](README.md)

A **page** is a row in the `pages` table. The GrapesJS visual editor stores its canonical state in `project_data` and a compiled snapshot in `html` + `css`; the public render route assembles those plus per-page CSS/JS, a reactive Alpine store and a small flow runtime into a standalone HTML document.

## The Page model

`src/Models/Page.php`. Route key is `slug`; soft-deletes enabled.

| Column | Type | Notes |
|---|---|---|
| `title` | string | |
| `slug` | string | Unique, route key. Pattern `^[a-z0-9\-_]+$` |
| `status` | `PageStatus` enum | `draft` \| `published` |
| `kind` | string | `page` (default) or `email` (an [email template](email.md)) |
| `requires_auth` | bool | Gate the page behind the `pb` guard |
| `project_data` | array (JSON) | GrapesJS canonical editor state |
| `html` | string | Compiled markup snapshot |
| `css` | string | Compiled stylesheet snapshot |
| `custom_css` | string | Per-page CSS (Advanced section) |
| `custom_js` | string | Per-page JS (Advanced section) |
| `meta` | array (JSON) | SEO: `title`, `description`, `og_image`, `canonical`, `noindex` |
| `published_at` | datetime | Set automatically when status is `published` |

Scopes: `published()`, `pages()`, `emailTemplates()`. Helpers: `isPublished()`, `isEmailTemplate()`. Saving/deleting a page busts the render cache for its slug (and the old slug if it changed).

`PageStatus` (`src/Enums/PageStatus.php`): `Draft = 'draft'`, `Published = 'published'`, each with `label()` and `color()`.

## Editing in Filament

`PageResource` form sections:

1. **Page details** — `title` (auto-fills `slug` on create), `status`, `kind`, `slug`, `requires_auth`.
2. **Builder** — the GrapesJS field (`builder`), holding `{ project_data, html, css }`. `PageDataMapper::merge()`/`split()` map between this form shape and the DB columns.
3. **SEO** (collapsed) — the `meta.*` keys.
4. **Advanced** (collapsed) — `custom_css` and `custom_js` (Ace code fields).

The list view offers **Duplicate** (clones to a Draft with a random slug suffix), **View live** (for published pages when the render route is enabled), and delete.

## The block vocabulary

`src/Blocks/BlockVocabulary.php` is the single source of truth for the editor's blocks **and** the AI's allowed component keys. Blocks come in families; each block has a `key`, `label`, `category`, `template` (HTML), `description` and an icon (`src/Blocks/SectionBlock.php` is the value object).

Section blocks wrap their markup in `<section data-pb-block="{key}">` with stable `pb-{key}__*` classes. The `data-pb-block` attribute is the convention that lets dragged or AI-generated markup import as a labelled, editable GrapesJS component — and it is the vocabulary the AI is constrained to (`BlockVocabulary::keys()` returns the section keys only).

### Sections (category `Sections`) — the AI vocabulary

`navbar`, `hero`, `features`, `logos`, `stats`, `gallery`, `pricing`, `testimonial`, `faq`, `team`, `cta`, `contact`, `footer`.

### Basics (category `Basic`) — no `data-pb-block`

`text`, `heading`, `image`, `button`, `columns-2`, `columns-3`, `spacer`, `divider`.

### Shapes (category `Shapes`)

`shape-wave`, `shape-slant`, `shape-tilt`, `shape-curve` — full-width SVG section dividers.

### Components (category `Components`)

`card`, `banner`, `modal`, `drawer`, `tabs`, `accordion`, `tooltip`, `dropdown_menu`. These ship structured, styled markup with **declarative** local state (`x-data`, `x-show`, `x-cloak`, `x-transition`) — overlay panels use `x-cloak` so they stay hidden in the editor canvas (where Alpine does not run). Because page `html` is sanitized (see below), any **user-triggered** behaviour (open/close, switch tab) must be wired from `custom_js` using the [sanitizer-safe pattern](#writing-interactive-pages-the-sanitizer-safe-pattern) — inline `@click` handlers on the block markup are stripped on save.

### Forms (category `Forms`)

`text_input`, `email_input`, `textarea`, `select`, `checkbox`, `radio_group`, `submit_button`, `form`. Each input is a real control with a stable `name` so the runtime's `collectFormInput()` picks it up.

### Data (category `Data`)

`data_table` and `list` — render rows reactively. `data_table` carries `x-data="pbTable('<collection>')"` and fetches `GET {api}/{collection}` on init; `list` repeats over a `$store.app` array. (See [`pbTable`](#data-tables-with-pbtable) below.)

> The full HTML template for every block lives in `BlockVocabulary.php`. `BlockVocabulary::all()` returns every family; `BlockVocabulary::toArray()` is the serialized form handed to the GrapesJS block manager.

## The render route

When `routes.render_enabled` is on (default), the package serves:

- `GET /{render_prefix}/{slug}` → `RenderPageController` (default prefix `p`, so `/p/{slug}`). `slug` matches `[A-Za-z0-9\-_]+`.
- `GET /{render_prefix}/` → the configured **home page** (`RenderPageController@home`).

`RenderPageController` resolves the page via the `published()` scope, returns 404 for unknown/unpublished slugs, and — if `requires_auth` is true and `auth.enabled` is true — redirects unauthenticated visitors to the login path (with `intended`). See [Authentication & permissions](authentication-and-permissions.md).

### Home page

The home page is **not** chosen in config — it is the `home_page` setting (a page slug), chosen on the *Settings* admin screen. `RenderPageController@home` reads it from the `Settings` service and 404s if unset.

To also serve the home page at the site root `/`, enable `routes.home_at_root` (`AI_PAGE_BUILDER_HOME_AT_ROOT=true`). This only takes effect if the host app has **no** `/` route of its own (Laravel matches the first-registered route). If your app keeps a `/` route, point it at the controller yourself:

```php
use Andre\AiPageBuilder\Http\Controllers\RenderPageController;
Route::get('/', [RenderPageController::class, 'home']);
```

### Caching

`PageRenderer` caches the assembled HTML per slug. Cache key prefix `ai-page-builder:rendered:`, TTL `cache.ttl` (default 3600s; `0` disables), store `cache.store`. The cache is busted automatically when a page is saved or deleted.

## Per-page CSS / JS

`custom_css` is injected into the page `<head>`. `custom_js` is injected at the end of `<body>` **before** the (deferred) Alpine script — so any component factory it defines is registered before Alpine boots and evaluates the first `x-data`. `custom_css` and `custom_js` are the two **raw, un-sanitized** channels (the owner owns them); everything in `html` is sanitized (see below).

### Writing interactive pages (the sanitizer-safe pattern)

Because `html` is always sanitized, executable behaviour that you'd normally inline in the markup is stripped. Put the behaviour in `custom_js` instead, and follow the same conventions the framework's own components use:

- **Define component factories in `custom_js`**, reference them declaratively in `html`:
  ```js
  // custom_js
  window.inventoryApp = () => ({
    rows: [], loading: true,
    init() { this.load(); },          // Alpine calls init() automatically — no x-init needed
    load() { fetch(this.api).then(r => r.json()).then(d => { this.rows = d.data; }); },
  });
  ```
  ```html
  <!-- html: x-data references the factory; x-init is not needed (and would be stripped) -->
  <div x-data="inventoryApp()"> … </div>
  ```
- **Need the reactive store at startup?** Register on `alpine:init` (it fires when the deferred Alpine starts, after your `custom_js` has run): `document.addEventListener('alpine:init', () => { window.Alpine.store('app').foo = 1; })`.
- **Handle clicks without `@click`** (which is stripped): tag buttons with a `data-*` attribute (kept by the sanitizer) and delegate inside `init()`:
  ```html
  <button data-act="openCreate">+ Add</button>
  ```
  ```js
  init() {
    this.$el.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-act]');
      if (btn) this[btn.dataset.act]?.();
    });
  }
  ```
  The bundled Inventory demo (`ai-page-builder:install-demo`) is authored exactly this way — read `src/Demo/InventoryDemo.php` for a full working example.

## Declarative data binding (Alpine)

The rendered page loads Alpine and registers a global store named `app`, seeded from your [States](functions-and-states.md): `Alpine.store('app', { ...window.__pbState })`. Bind page content to state with **declarative** directives over `$store.app.<state>`:

| Directive | Use |
|---|---|
| `x-text="$store.app.greeting"` | Render a state value as text |
| `x-show="$store.app.isOpen"` | Toggle visibility |
| `x-model="$store.app.search"` | Two-way bind an input |
| `x-for="item in $store.app.items"` | Repeat over a state array |

> **Page `html` is always sanitized — it's the XSS surface served verbatim to visitors** — so this applies to hand-authored *and* AI-authored markup alike. The [`HtmlSanitizer`](ai.md#safety) strips executable directives (`@click`, `x-on:*`, `x-init`, `x-effect`, `x-html`) and `<script>` from `html`, but keeps the declarative ones (`x-data`, `x-show`, `x-text`, `x-model`, `x-for`, `x-bind:`/`:`, `x-cloak`, `x-transition*`) and the `data-*` / `data-pb-*` attributes. Executable behaviour belongs in the raw `custom_js` channel — see [the sanitizer-safe pattern above](#writing-interactive-pages-the-sanitizer-safe-pattern).

The current end-user is fetched client-side from `GET /pb-auth/me` and placed at `$store.app.$user` — used to drive component visibility (see below).

## Runtime data attributes

The bundled `flow-runtime.blade.php` wires up these attributes on the published page:

| Attribute | Effect |
|---|---|
| `data-pb-block="{key}"` | Marks a section block (editor + validator convention) |
| `data-pb-flow="{slug}"` | Run a flow when the element is clicked/submitted |
| `data-pb-flow-event="click\|submit\|…"` | Which event triggers the flow |
| `data-pb-flow-input='{"k":"v"}'` | Explicit input merged with nearest-form data |
| `data-pb-record="{collection}"` | A `<form>` that creates a record in a collection on submit |
| `data-pb-page="{slug}"` | Navigate to another page on click |
| `data-pb-auth` | Hide the element unless an end-user is logged in |
| `data-pb-roles="a,b"` | Hide unless the user has one of those role slugs (admins always pass) |

Flow runs `POST` to `/{flow_prefix}/{slug}` with `{ "input": {...} }` and apply the returned `actions[]` (`setHtml`, `setText`, `notify`, `redirect`, `addClass`, `removeClass`, `setState`, `setStates`). See [Flows](flows.md).

## Data tables with `pbTable`

For a data-bound table, give the root `x-data="pbTable('<collection key>')"`. On init it fetches `GET {api_prefix}/{collection}?expand=*` and exposes:

- `rows` — the records (`response.data`)
- `loading` / `error` — request state

Then repeat with `<template x-for="row in rows" :key="row.id">` and bind cells with `x-text="row.<field>"`. The `data_table` block ships this scaffold plus sample rows (hidden with `x-show="false"`) so the editor canvas shows something while Alpine is inert.

**Auto-render:** a bare `data_table` shell (no explicit column markup) renders columns directly from the fetched data — relation fields show the resolved `name` from the expanded relation, never the raw id. The table is never blank as long as the collection has rows. KPI and chart widgets render from a configured-but-empty wrapper without requiring manual column scaffolding.

## Management pages

A **management page** is a standard page pattern that pairs a create form with a live data table and per-row actions.

### Structure

1. **Add form** — a `<form data-pb-record="<collection>">` with one labelled input per field. Field types map to input types:
   - Text/number fields → `<input type="text|number">`
   - Select fields → `<select>` with `<option>` per choice
   - Relation fields → `<select>` populated from a collection list (`x-data="pbTable('<related>')"`) so the dropdown shows related record names
   - Image fields → file input (see [Image fields](#image-fields) below)

2. **Data table** — a `data_table` block that auto-refreshes after a successful create (the `data-pb-record` runtime re-fetches the list on `201`). Uses `?expand=*` so relation columns show names.

3. **Per-row Edit and Delete** — on each row:
   - **Edit** — fills the Add form with the row's current values and switches the form's POST to a PUT to `{api}/{collection}/{id}`.
   - **Delete** — sends a DELETE to `{api}/{collection}/{id}` and removes the row from the table.

Both actions go through `RecordQuery`, so validation, column mapping and permission rules apply.

### Image fields

An image field in a form is a `<input type="file" accept="image/*">`. On file selection the runtime immediately uploads the file to `POST /pb-upload` (see [Public upload endpoint](#public-upload-endpoint)) and puts the returned URL into a hidden input. The form then submits the URL string as the field value — the collection stores a plain URL, no binary data.

**Displaying images.** The stored URL renders as an image wherever the value flows:

- **Auto data table** — a cell whose value looks like an image URL (`.png/.jpg/.jpeg/.gif/.webp/.svg/.avif`) renders as a small thumbnail `<img>` instead of raw URL text.
- **Record picker** — set `data-pb-image-field` (default `image`) and `data-pb-price-field` (default `price`); each tile then shows the record's image thumbnail and price above the label, and every pick carries `{id, label, image, qty, price}` into the target cart state (so a cart/grid can show the image too). This is how a POS product picker shows each product's photo and price.
- **Curated pages** — bind `<img :src="row.<field>">` in an `x-for` to place the image exactly where you want it.

## Public upload endpoint

`POST /pb-upload` — a gated endpoint for image uploads from generated forms.

**Request:** `multipart/form-data` with a single `file` field (image only).

**Behavior:**

- **Authentication** — authenticated by default (requires the `pb` end-user session). Set `AI_PAGE_BUILDER_UPLOADS_ANON=true` to allow unauthenticated uploads (opt-in; off by default for safety).
- **Image-only** — accepts `image/jpeg`, `image/jpg`, `image/png`, `image/gif`, `image/webp`. Any other MIME type returns `422`.
- **Size cap** — rejects files over `uploads.max_kb` (default 5 120 KB = 5 MB). Returns `413` when exceeded.
- **Rate-limited** — same rate-limit infrastructure as the flow run endpoint.
- **Safe filenames** — the stored filename is a UUID + the original extension; the original filename is never used.
- **Response** — `200 { "url": "https://..." }` on success; standard error codes on failure.

Configuration (see [Configuration → `uploads`](configuration.md#uploads)):

| Key | Env | Default | Meaning |
|---|---|---|---|
| `uploads.allow_anonymous` | `AI_PAGE_BUILDER_UPLOADS_ANON` | `false` | Allow unauthenticated uploads |
| `uploads.max_kb` | `AI_PAGE_BUILDER_UPLOADS_MAX_KB` | `5120` | Max upload size in KB |

Next: [Collections & data](collections-and-data.md).
