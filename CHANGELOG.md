# Changelog

All notable changes to `andrecorugda/synapse` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
