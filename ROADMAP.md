# Synapse — Roadmap

Forward-looking plan for Synapse (composer `andrecorugda/synapse`; namespace `Andre\AiPageBuilder`).
Items are grouped into three waves by sequencing, not strict order. ⭐ marks high-leverage work;
⭐⭐ marks the bets that most directly close Synapse's competitive gaps (ecosystem maturity +
connector breadth). "Foundation" notes call out what already exists to build on.

---

## Status (2026-06-30)

- **Wave 4 — ✅ shipped (extensibility & security).** A security-hardening pass
  (SSRF guard on the HTTP node, CSRF guard on cookie data-API writes, sanitize-page-
  HTML-on-every-save, collection-key DDL lockdown, SSO verified-email gating, hashed
  2FA recovery codes, PHP-function eval **off by default**), then a **capability
  registry** backbone that makes flows AND components **extensible**: **loop** +
  **transaction** flow nodes (atomic, eval-free), a curated **helper library**
  (db/ui/auth/util, in the expression sandbox — the no-eval power path), a
  searchable categorized **node drawer** + helper dropdown, and a public registration
  API — `PageBuilder::registerNode()` / `registerHelper()` / **`registerComponent()`**
  — plus a `capabilities()` catalogue that doubles as an **MCP/AI tool list**.
  Third-party / premium packages can now ship nodes, helpers, and components with no
  core change. Proven end-to-end with an inventory + POS build.
- **Wave 1 — ✅ shipped & verified.**
- **Wave 2 — ✅ shipped & verified**, plus extras beyond the original plan:
  collections **API tokens + API docs** (Bearer auth), seeded/settable **404 +
  maintenance + home** pages, **page versioning** (preview + apply a version),
  **draft preview** (signed URL), and a flow **"Run now"** action.
- **Wave 3 — ✅ flagship complete (1.0).** Every ⭐ headline feature + the big
  subsystems shipped, tested, live-verified, and documented: ⭐ **external data
  sources**, the full **Identity & Auth subsystem** (password toggle, forgot/reset,
  self-registration + onboarding modes, **SSO** Google/Microsoft/GitHub with
  org/domain/tenant restriction, email **invites**, **2FA** email-OTP + TOTP +
  recovery, **logout** trait + flow action, guest-redirect), **record ownership**
  (user-relation fields), **credentials store**, plus the earlier CSV import/export,
  SEO (sitemap/robots), +12 components, AI HtmlSanitizer, API-token auth, image
  field, and field-level permissions. The admin menu is purpose-grouped and the
  product is branded **Synapse** throughout (site + docs aligned, AI positioned as
  an optional accelerator).

- **Post-1.0 backlog (deferred — depth & ecosystem, none blocking):**
  ⭐⭐ template/component **marketplace** (now has a code foundation — see Wave 5 — but
  is still mostly community/hosting); **record history** (data revisions); **AI depth**
  (edit-existing-section, image generation, streaming chat, usage/cost panel; the
  `capabilities()` catalogue already gives an AI agent the tool list); **SEO/i18n**
  extras (OG-image/JSON-LD helpers, multi-language content); **more components**
  (rich-text/markdown, maps, kanban, steppers, conditional visibility); **platform**
  (backups/snapshots, Sentry/observability, CLI scaffolder); **external HTTP/REST API**
  as a virtual collection.

The package test suite is green (~340 passing; the only failures are GD-dependent
media tests on the bare CI image) and phpstan is clean. Shipped work is on `main` /
`develop`; the repo follows [BRANCHING_STRATEGY.md](BRANCHING_STRATEGY.md) with
protected branches + required CI.

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

> **1.0 status:** the ⭐ flagship items below are shipped. Items still marked `[ ]`
> or `[~]` are the **post-1.0 backlog** (depth & ecosystem) — deferred, not gaps.


- [x] ⭐ **External data sources ✅ SHIPPED 2026-06-29** — a collection can be `external`: it maps to an
  EXISTING table on any configured DB connection (`source_type`/`source_connection`/`table_name`), which
  the package reads through the *same* data layer (RecordQuery / REST API / data-table+chart+autocomplete
  blocks / flows / permissions / ownership) but never creates, alters, or drops (`SchemaSynchronizer`
  skips it). `is_read_only` blocks writes (external defaults to read-only). Fields *describe* the existing
  columns. *Open follow-up:* external **HTTP/REST API** as a virtual collection (a bigger query→HTTP layer).
- [x] **Credentials store** — ✅ encrypted secrets (bearer / api-key / basic) the HTTP node uses by
  key (`config.credential`); managed in a Credentials resource.
- [ ] ⭐⭐ **Template & component marketplace** — community starter apps + components. The fastest
  way to close the "young ecosystem" gap.
- [~] **Data depth** — ✅ **CSV import/export**, ✅ **image field** (media-bound), ✅ **field-level
  permissions** (per-role/action allow-list, REST projects reads + strips writes); open: record
  history (data revisions).
- [x] **Record ownership = user relations** — *(Identity & Auth Phase 1)* the relation (belongs-to)
  field type now targets **App users** by default, not just other collections, so a collection can
  carry **several named user foreign keys** (author / approver / assignee …), each renamable —
  ownership is just a relation, no dedicated system column. Mechanics reused as-is: column `{key}_id`,
  `exists:` validation + `expand=` resolution route to `PbUser` (password hidden), and a create
  permission rule `{"<field>_id":"$CURRENT_USER"}` auto-stamps the logged-in user while the same rule
  scopes reads/writes. *(Chosen over a single `has_owner`/`owner_id` column — that couldn't model
  multiple user roles on one collection.)*
- [x] **Auth depth — Identity & Auth subsystem ✅ SHIPPED 2026-06-29** *(phased; SSO/TOTP via OPTIONAL
  deps — laravel/socialite, socialiteproviders/microsoft, pragmarx/google2fa, `class_exists`-guarded;
  email-OTP needs no dep).* All phases done, verified + committed:
  1. ✅ **Record ownership** = user relations (above).
  2. ✅ **Password-login toggle + forgot/reset + self-registration + approval/status** — `auth.password_login`;
     self-contained hashed reset tokens + emails; `status` (pending|active|suspended) folded into login.
     Onboarding is **admin-configurable** (invite-only | approval | open + email-domain allow-list).
  3. ✅ **SSO providers** — Google / Microsoft / GitHub, each with **org/domain/tenant restriction**
     (enforced server-side); `provider`/`provider_id` columns; `password` nullable; graceful when Socialite absent.
  4. ✅ **Invites + admin approval/invite UI** — hashed-token invites, Send/Resend/Revoke, accept flow.
  5. ✅ **2FA** — login challenge; **email-OTP** + **authenticator TOTP** + hashed recovery codes; admin reset.
  Cross-cutting (done): config nested under `auth.*`; sibling controllers; throttling; runtime-editable on the
  **Synapse Settings → Identity & Auth tab** (dropdowns/toggles, not free fields). Email verified live via SMTP.
- [~] **AI depth** — ✅ **`HtmlSanitizer` is wired on the AI path** (allows declarative directives,
  strips executable); open: edit-existing-section refinement, AI image generation, streaming chat,
  usage / cost panel.
- [~] **SEO & i18n** — ✅ **`sitemap.xml` + `robots.txt` shipped** (noindex-aware); open: OG-image /
  JSON-LD helpers, multi-language page content.
- [~] **More components** — ✅ **date picker + file upload shipped** (plus video, breadcrumbs, rating,
  progress, alert, avatar — 55 blocks total); open: rich-text/markdown, maps, kanban, steppers, conditional visibility.
- [ ] **Platform** — backups / snapshots; Sentry / observability; CLI scaffolder.

## Wave 4 — extensibility & security ✅ shipped (2026-06-30)

- [x] **Security hardening pass** — SSRF guard (HTTP node), CSRF guard (cookie data-API
  writes), sanitize page HTML on every save, collection-key DDL lockdown, SSO verified-
  email gating, hashed 2FA recovery codes, and PHP-function `eval` **off by default**.
- [x] ⭐ **Capability registry spine** — one `CapabilityDefinition` describing nodes,
  helpers, and components; feeds the canvas drawer, the helper dropdown, and the MCP/AI
  catalogue from a single source. Backward-compatible (`ProvidesNodeDefinition` is opt-in).
- [x] ⭐⭐ **Flow extensibility** — `PageBuilder::registerNode()` / `registerHelper()`;
  **loop** + **transaction** nodes (atomic, all-or-nothing, **no eval**); a curated
  **helper library** (`db_*`/`ui_*`/`auth_*`/`util_*`) in the expression sandbox — the
  eval-free power path; a searchable, categorized **node drawer** + helper dropdown.
- [x] ⭐⭐ **Component extensibility** — `ComponentRegistry` + `PageBuilder::registerComponent()`;
  `BlockVocabulary` delegates so a registered/premium block appears in the editor block
  manager, the catalogue, and (if it declares the `Sections` category) the AI vocabulary —
  **no core change required.**
- [x] **MCP / AI tool catalogue** — `PageBuilder::capabilities()` + the
  `ai-page-builder:capabilities` command emit an MCP-tool-shaped list (label → name,
  description, usage, inputs) of every node, helper, and component.

## Wave 5 — open-core & commercialization ⭐⭐ (planned)

> Wave 4's extensibility is the foundation for this. Premium = **separate,
> commercially-licensed packages** that `require` the MIT core and register through the
> public API. The core stays MIT and genuinely capable; paid packages add depth.

- [ ] ⭐⭐ **Premium component & node packs** — proprietary-licensed add-on packages
  (advanced blocks, integrations, specialized flow nodes) that plug in via
  `registerComponent()` / `registerNode()` and appear automatically in the builder.
- [ ] ⭐⭐ **Licensing & distribution** — a **private Composer repository gated by a
  per-customer license key** (Anystack / Private Packagist / Satis), with a commercial
  license on the premium packages. (PHP is source-distributed: the model is distribution
  control + license terms, not DRM.)
- [ ] **"Pro" upsell UX** — a `tier` flag on `CapabilityDefinition` and **"Pro" badges**
  for not-yet-licensed capabilities in the node drawer + block manager.
- [ ] ⭐⭐ **Template & component marketplace** — community + premium starter apps and
  components, now feasible on the `ComponentRegistry` + the existing app export/import.
- [ ] **Stable extension-API contract** — version the public registry +
  `CapabilityDefinition` surface (semver discipline) so premium packs don't break on a
  core upgrade. *(A maintenance commitment that comes with selling against this API.)*

---

## Why these (competitive rationale)

Synapse's edge is breadth in one self-hosted, MIT, Laravel/Filament-native plugin (pages + typed
data + REST + n8n-style flows + end-user auth + AI-builds-apps). Its real weaknesses vs the field
are **ecosystem maturity** and **connector breadth**. The starred items attack exactly those:

- **Relations + external data sources** → close the gap to Directus / Retool / ToolJet.
- **App export/import + template/component marketplace** → close the "young, narrow ecosystem" gap.
- **Page versioning + flow run-history** → raise trust in AI-generated changes.
- **Extensibility (nodes / helpers / components) + open-core (Wave 5)** → a community
  contribution surface *and* a sustainable funding path (premium packs) without
  compromising the MIT core — the answer to "great OSS builders that die unmaintained."

See the platform comparison for context: Directus (now source-available MSCL), n8n (fair-code,
no embedding), Retool / Lovable (SaaS). Synapse stays MIT + self-hosted + in-your-app.
