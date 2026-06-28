# Synapse — App Builder · Documentation

Synapse is a self-hosted, low-code **app builder** that lives inside the Laravel + Filament admin you already run. You build pages (GrapesJS), define your own data tables (Collections), wire automations (Flows), gate everything behind your app's own users and roles — and an optional AI layer can generate or refine the whole thing from a plain-language brief. Everything you build is stored as ordinary database rows, and nothing leaves your server.

These docs are the reference: how it installs, how each subsystem works, the exact config keys, routes, table names, field types, node shapes and query syntax — grounded in the package source.

## Pick your path

**No-code / app author** — you live in the Filament admin and want to ship an app:

1. [Installation](installation.md) — get the panel plugin running.
2. [Architecture](architecture.md) — the mental model in five minutes.
3. [Pages & components](pages-and-components.md) — build and publish pages.
4. [Collections & data](collections-and-data.md) — your own data tables.
5. [Flows](flows.md) — make things happen on clicks and data events.
6. [AI](ai.md) — describe an app, review the plan, apply it, refine by chat.

**Developer / integrator** — you embed Synapse in a host app, extend it, or self-host its assets:

1. [Installation](installation.md) and [Configuration](configuration.md) — full env + config reference.
2. [Architecture](architecture.md) — services, models, the request lifecycle.
3. [Functions & States](functions-and-states.md) — runtimes, the reactive store.
4. [Authentication & permissions](authentication-and-permissions.md) — the `pb` guard, opt-in permission model, row-level rules.
5. [Email](email.md) — the isolated SMTP transport and templates.
6. [Extending](extending.md) — swap models, register custom flow nodes/blocks, the `AiInvoker` contract, observers.

## All pages

| Page | What it covers |
|---|---|
| [installation.md](installation.md) | Requirements, `composer require`, plugin registration, publishing migrations/assets, optional AI gateway, env vars. |
| [architecture.md](architecture.md) | The seven pillars, "everything is data," and the request/AI lifecycle (with diagrams). |
| [pages-and-components.md](pages-and-components.md) | GrapesJS builder, block vocabulary, per-page CSS/JS, the render route, home page, `requires_auth`, declarative Alpine binding, `pbTable`. |
| [collections-and-data.md](collections-and-data.md) | Collections → `pb_<key>` tables, field types, the auto REST API, the `filter`/`sort`/`search`/paginate syntax, schema sync. |
| [flows.md](flows.md) | The flow engine: triggers, every node type and its config, `FlowContext` interpolation, the public run endpoint, cron. |
| [functions-and-states.md](functions-and-states.md) | Functions (expression / callable / PHP) and States (the persistent reactive store). |
| [authentication-and-permissions.md](authentication-and-permissions.md) | The built app's users/roles/permissions, the `pb` guard, opt-in gating, row-level rules, component visibility. |
| [email.md](email.md) | SMTP settings, the isolated transport, email-template pages, the `send_email` node, mustache interpolation, send-test. |
| [ai.md](ai.md) | The Build Plan contract, "Build with AI," the floating chat, idempotent apply, the code-generated system prompt, safety. |
| [configuration.md](configuration.md) | A table of **every** key in `config/ai-page-builder.php`, with env var, default and meaning. |
| [extending.md](extending.md) | Swapping models, custom flow nodes, custom blocks, the `AiInvoker` contract, events/observers. |

## Conventions used in these docs

- **Collection** = a user-defined data model (a real DB table named `pb_<key>`).
- **State** = a persistent, app-wide global variable (the reactive store; the Filament resource is labelled "States," the model is `Variable`).
- **Flow** = an automation graph; **Function** = a reusable unit of logic a flow can call.
- **Built app** vs **host app**: the *host* is your Laravel application; the *built app* is what you author with Synapse (its pages, data and its own `pb`-guard users).
