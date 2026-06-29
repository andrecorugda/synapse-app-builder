# Synapse — Roadmap

Forward-looking plan for Synapse (composer `andrecorugda/synapse`; namespace `Andre\AiPageBuilder`).
Items are grouped into three waves by sequencing, not strict order. ⭐ marks high-leverage work;
⭐⭐ marks the bets that most directly close Synapse's competitive gaps (ecosystem maturity +
connector breadth). "Foundation" notes call out what already exists to build on.

---

## Status (2026-06-29)

- **Wave 1 — ✅ shipped & verified.**
- **Wave 2 — ✅ shipped & verified**, plus extras beyond the original plan:
  collections **API tokens + API docs** (Bearer auth), seeded/settable **404 +
  maintenance + home** pages, **page versioning** (preview + apply a version),
  **draft preview** (signed URL), and a flow **"Run now"** action.
- **Wave 3 — 🟡 partial.** Shipped: **CSV import/export**, **SEO** (sitemap.xml +
  robots.txt), the **+12 components** batch, the **AI HtmlSanitizer** on the AI
  path, and **API-token auth** (under Auth depth). Still open below are the
  larger initiatives — external data sources, a hosted marketplace, full
  SSO/2FA, field-level permissions, record history, credentials store, the
  remaining AI-depth items, i18n, and platform/observability.

The package test suite is green (183 passing; the only failures are GD-dependent
media tests on the bare CI image). All shipped work is on `main`.

---

## Wave 1 — committed near-term

- [x] **Dynamic crons** — a `schedules` table + a single `Kernel::schedule()` hook that loops
  registered rows (cron expression each) and dispatches flows/functions.
  *Foundation: `RunCronFlowsCommand` already runs flow crons.* Must stay route/cache-safe — no
  closures capturing outer state (that broke a deploy before). Pairs with the **schedule trigger** (Wave 2).
- [x] **Charts / dashboard pack** — server-side aggregate endpoint on `RecordQuery`
  (group / count / sum / avg / time-bucket) → chart blocks (line / bar / donut / area) + KPI cards,
  bound to a collection or a State. Vendored Chart.js (no CDN), with BlockVocabulary + AI support.
- [x] ⭐ **Page versioning** — `page_revisions` table snapshotting
  `{project_data, html, css, custom_css, custom_js, meta}` on every save/publish; diff + "restore
  this version" UI. The safety net that makes AI edits non-scary. *Not started.*
- [x] ⭐ **AI uses the custom CSS / JS channels** — teach `SystemPromptBuilder` + `BuildPlanApplier`
  to route styles into `custom_css` and behavior into `custom_js` (and reference shared theme
  tokens) instead of inlining everything → keeps generated pages configurable.
  *Foundation: `custom_css` / `custom_js` columns exist; integrates with the editor frame-CSS
  preservation already shipped.*
- [x] ⭐ **Flow error handling** — (1) an `on-error` branch / try-node on the canvas,
  (2) per-node retry + a `failed` `FlowRun` status with the error captured, (3) a **notify result
  action** (toast / banner / email) so failures surface instead of dying silently.
  *Foundation: `FlowRun` telemetry + ResultNode actions exist.*
- [x] **New components** — iframe / embed (host allow-list), autocomplete bound to a collection
  (typeahead against `/api/pb/{collection}`), pagination control for the data table / list.

## Wave 2 — high leverage

- [x] ⭐ **Collection relationships** — belongs-to / has-many / many-to-many + a relation field type
  + eager-loading in the REST API. The single most-expected data feature Synapse still lacks.
- [x] ⭐ **Global theme tokens** — define brand colors / fonts / spacing once; pages + AI inherit
  them. What truly makes pages "configurable"; natural home for the Wave 1 custom-CSS work.
- [x] **Draft + preview** — view/share an unpublished page via a signed preview link.
- [x] ⭐ **Flow run history / logs UI** — surface the existing `FlowRun` telemetry with a step
  inspector + replay. Prerequisite for flow error handling to be actually useful.
- [x] **Schedule + webhook flow triggers** — `schedule` (ties to dynamic crons) and inbound
  `webhook` trigger types; plus form-submit and manual "run now."
- [x] ⭐⭐ **App export / import** — serialize a whole app (collections + pages + flows + functions
  + states + settings) to JSON and re-import. Enables backups, staging→prod promotion, and templates.
- [x] **Reusable partials / symbols** — one header/footer edited once, used across pages.

## Wave 3 — depth & moat

- [ ] ⭐ **External data sources** — read/write an existing DB table or external API as a "virtual
  collection." The Retool / ToolJet moat; even read-only is a big unlock.
- [ ] **Credentials store** — reusable API / OAuth secrets for HTTP / integration nodes (toward an
  n8n-style integration story).
- [ ] ⭐⭐ **Template & component marketplace** — community starter apps + components. The fastest
  way to close the "young ecosystem" gap.
- [~] **Data depth** — ✅ **CSV import/export shipped**; open: file/image field bound to the media
  library, field-level permissions, record history (data revisions).
- [~] **Auth depth** — ✅ **API tokens / key auth shipped** (Bearer → pb guard, AccessControl-scoped,
  + API docs page); open: SSO / social login + magic links + 2FA; self-registration + profile page.
- [~] **AI depth** — ✅ **`HtmlSanitizer` is wired on the AI path** (allows declarative directives,
  strips executable); open: edit-existing-section refinement, AI image generation, streaming chat,
  usage / cost panel.
- [~] **SEO & i18n** — ✅ **`sitemap.xml` + `robots.txt` shipped** (noindex-aware); open: OG-image /
  JSON-LD helpers, multi-language page content.
- [~] **More components** — ✅ **date picker + file upload shipped** (plus video, breadcrumbs, rating,
  progress, alert, avatar — 55 blocks total); open: rich-text/markdown, maps, kanban, steppers, conditional visibility.
- [ ] **Platform** — backups / snapshots; Sentry / observability; CLI scaffolder.

---

## Why these (competitive rationale)

Synapse's edge is breadth in one self-hosted, MIT, Laravel/Filament-native plugin (pages + typed
data + REST + n8n-style flows + end-user auth + AI-builds-apps). Its real weaknesses vs the field
are **ecosystem maturity** and **connector breadth**. The starred items attack exactly those:

- **Relations + external data sources** → close the gap to Directus / Retool / ToolJet.
- **App export/import + template/component marketplace** → close the "young, narrow ecosystem" gap.
- **Page versioning + flow run-history** → raise trust in AI-generated changes.

See the platform comparison for context: Directus (now source-available MSCL), n8n (fair-code,
no embedding), Retool / Lovable (SaaS). Synapse stays MIT + self-hosted + in-your-app.
