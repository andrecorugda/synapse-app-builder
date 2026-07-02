# Flows

[← Docs index](README.md)

A **flow** is the automation engine — an n8n-style graph of nodes that runs in response to a trigger. A flow is a row in `page_builder_flows`; its graph lives in the `definition` column. The visual canvas (Drawflow) edits it; `FlowRunner` executes it; `FlowManager` records each run to `page_builder_flow_runs`.

## The Flow model

`src/Models/Flow.php`. Route key `slug`; soft-deletes; scope `active()` (where `is_active = true`).

| Column | Type | Notes |
|---|---|---|
| `slug` | string | Route key, `^[a-z0-9\-_]+$` |
| `name` | string | |
| `trigger_type` | string | Advisory **label** only (`manual` \| `component` \| `form` \| `collection` \| `cron` \| `api`) — no longer gates execution; see [Triggers](#triggers) |
| `is_active` | bool | Inactive flows never run |
| `is_public` | bool | Required for the public run endpoint (unauthenticated trigger) |
| `rate_limit_per_minute` | int\|null | Per-flow override of the global rate limit |
| `trigger_config` | array (JSON) | Legacy trigger config; collection/state binding now lives on [Watchers](watchers.md) |
| `definition` | array (JSON) | The node graph |

### Definition graph shape

```json
{
  "start": "<node id>",
  "nodes": {
    "<node id>": {
      "type": "<node type>",
      "config": { },
      "next": ["<node id>"]
    },
    "<branch node id>": {
      "type": "condition",
      "config": { "left": "...", "op": "equals", "right": "..." },
      "next_true": ["<node id>"],
      "next_false": ["<node id>"]
    }
  }
}
```

`FlowRunner` starts at `definition.start`, looks up each node's handler in the `NodeRegistry` by `type`, runs it, and enqueues the node ids the handler returns (`next`, or `next_true`/`next_false` for a condition). It records a step per node into the run telemetry and stops at an empty queue or `flow.max_steps` (default **1000**).

> **The step budget is global.** `flow.max_steps` is one budget for the whole run — it is *shared* across nested [loop](#loop) and [transaction](#transaction) bodies (a body sub-graph runs against the same context and increments the same counter), so a runaway loop is bounded by the same cap. Raise it with `AI_PAGE_BUILDER_FLOW_MAX_STEPS` if a legitimate loop genuinely needs more passes than the default allows.

## Triggers

A flow is a **reusable graph** — *what* fires it is configured separately, not
baked into the flow. `trigger_type` on the flow row is now only an advisory
**label**; it no longer gates execution.

### From a page or the API

On a page, an element with `data-pb-flow="<slug>"` runs the flow on its
`data-pb-flow-event` (default click/submit); the nearest `<form>`'s fields plus
any `data-pb-flow-input` JSON, merged over the live `$store.app` state, become the
flow `input`. External callers POST the same [public run endpoint](#the-public-run-endpoint).
Both are governed by **Public/Private** (a private flow is same-origin only; a
public flow is callable from anywhere). See [Pages → runtime attributes](pages-and-components.md#runtime-data-attributes).

You can also run any flow from the admin with the **Run now** button on its edit
page.

### On collection or state changes → Watchers

Reacting to a record write (created/updated/deleted) or a State change is the job
of a [**Watcher**](watchers.md), which binds one event to one target flow —
including a *different* flow per event, optional criteria/changed-field/transition
conditions, and live browser-side state watching. See [Watchers](watchers.md).

> Older flows (and AI plans) that still carry `trigger_type: collection` +
> `trigger_config` keep working: applying such a plan **materializes the
> equivalent watchers**. New work should create watchers directly.

### On a schedule → cron

`php artisan ai-page-builder:run-cron-flows` runs every active flow with
`trigger_type = cron` (empty input), isolating per-flow failures — schedule it as
in [Installation](installation.md#5-schedule-cron-flows-optional). For finer
control (per-flow cron expressions, function targets), prefer **Schedules** (see
[Configuration](configuration.md)).

## The public run endpoint

`POST /{flow_prefix}/{slug}` (default prefix `pb-flow`, so `POST /pb-flow/{slug}`). Stateless (`api` middleware, no CSRF). Enabled by `flow.run_route_enabled` (default true).

**Request:**

```json
{ "input": { "email": "a@b.com" } }
```

**Behavior** (`FlowController`):

- The flow must be `active()` **and** `is_public` — otherwise `404`.
- **Rate limited** per `slug` + client IP. Key: `pb-flow:{slug}:{ip}`. Limit: the flow's `rate_limit_per_minute`, else `flow.rate_limit_per_minute` (default 30), over a 60-second window. Over the limit → `429 {"error":"Too many requests"}`.
- On success → `200 { "actions": [ ... ] }` (the page runtime applies them).
- On failure → `500 {"error":"Flow failed"}` (the real error is logged, not leaked).

## Node types

Every node has `{ "type": "...", "config": { ... }, "next": [...] }`. Below is each registered type, its `config`, and what it writes into the [FlowContext](#flowcontext-interpolation). Registered in the service provider's `NodeRegistry` singleton.

> **Adding nodes on the canvas.** The toolbar's **+ Add node** button opens a left-anchored, searchable drawer that lists every registered node grouped by category (Flow Control, Data, UI & Feedback, Communication, …) — including any [custom nodes you register](extending-flows.md). It replaces the old fixed palette and stays current as nodes are added, because it is built from the live [capability catalogue](extending-flows.md#mcp--ai-exposure).

### Single entry point

Every flow begins with **exactly one Trigger node**, badged **START** in the editor. Attempting to add a second Trigger is blocked. Branch to different outcomes from there with Condition nodes.

### `trigger`

Entry node. No config — hands off to `next`. Every flow's `start` must be a trigger (the editor enforces one per flow).

### `record`

Read/write a collection through `RecordQuery`.

```json
{ "type": "record", "config": {
  "model": "leads",
  "operation": "list",
  "id": "{{ input.id }}",
  "filter": { "status": { "eq": "active" } },
  "sort": "-created_at",
  "search": "acme",
  "per_page": 25,
  "page": 1,
  "data": { "name": "{{ input.name }}" },
  "output": "records"
} }
```

`operation`: `list` \| `get` \| `create` \| `update` \| `delete`. `id` for get/update/delete; `filter`/`sort`/`search`/`per_page`/`page` for list; `data` for create/update. Writes the result to `vars[output]` (default `records`).

> **Write failures propagate; reads stay graceful.** A failing **write** (`create`/`update`/`delete`) re-throws, so the failure surfaces to the run's error handling and — crucially — a wrapping [Transaction](#transaction) rolls back. (Silently swallowing a failed write would let a transaction "succeed" on half-written data.) A failing **read** (`list`/`get`) is graceful: it logs a warning and writes `null` to the output var. The `model` (collection) is author-fixed and never interpolated from caller input, so a public flow's caller can't redirect the operation to another collection.

### `set_variable`

Persist an app [State](functions-and-states.md) (global) and/or a context var.

```json
{ "type": "set_variable", "config": {
  "key": "lead_count",
  "value": "{{ vars.records.total }}",
  "type": "number",
  "output": "count"
} }
```

`type` (optional cast): `string` \| `number` \| `boolean` \| `json`. Persists to `VariableStore` when `key` is set; also writes `vars[output]` when `output` is set.

### `condition`

Branch on a comparison; routes to `next_true` / `next_false`.

```json
{ "type": "condition", "config": { "left": "{{ vars.count }}", "op": "gt", "right": "0" } }
```

`op`: `equals` \| `not_equals` \| `contains` \| `gt` \| `lt` \| `empty` \| `not_empty`.

### `loop`

Iterate an array, running a **body sub-graph** once per element.

```json
{ "type": "loop", "config": {
  "over": "vars.cart_items",
  "item_var": "item",
  "index_var": "index",
  "max_iterations": 500,
  "output": "loop_result",
  "body": { "start": "<body node id>", "nodes": { } }
}, "next": ["<after-loop node id>"] }
```

- `over` — a **context path** (e.g. `vars.cart_items`, `input.lines`) resolving to the array to walk. A single `{{ path }}` token works too; a non-array yields zero passes.
- `item_var` / `index_var` — each pass binds the current element to `vars[item_var]` (default `item`) and its zero-based position to `vars[index_var]` (default `index`); the element's array key is also exposed as `vars[item_var + "_key"]`. The body therefore reads the current element as `{{ vars.item }}`.
- `max_iterations` — caps the pass count (independent safety valve; hard ceiling 10000).
- `output` — optional; when set, stores `{ "count": <iterations> }` under this var after the loop.
- `body` — an inline `{ "start", "nodes" }` sub-graph (its first node is wired to the node's **body** output handle on the canvas). It runs against the **same** `FlowContext`, so the body's record writes and vars accumulate into the run.

**Output handles:** `body` (the sub-graph) and `next` (continues after the loop). If the body fails on any iteration the loop stops and **re-throws**, so the enclosing handler decides what happens — most usefully, a `transaction` wrapping the loop rolls every prior write back. The body shares the global [`flow.max_steps`](#definition-graph-shape) budget.

### `transaction`

Run a body sub-graph atomically inside a database transaction — every write commits together, or all roll back if any step fails.

```json
{ "type": "transaction",
  "committed": "<node id on success>",
  "rolled_back": "<node id on failure>",
  "config": { "body": { "start": "<body node id>", "nodes": { } } }
}
```

- `config.body` — the inline `{ "start", "nodes" }` sub-graph wired to the **body** handle, run against the same context.
- **Branches are node-level keys** (siblings of `config`, like `next`): `committed` is followed when the body succeeds, `rolled_back` when it fails. (`committed` falls back to `next` if unset; if the body fails and no `rolled_back` is wired, the failure surfaces like any other node error.)

**Output handles:** `body`, `committed`, `rolled_back`. On success the transaction **commits** and the flow takes `committed`. On failure — a body node throws, or soft-fails via `FlowContext::$failed` (e.g. a [`util_assert`](functions-and-states.md#the-helper-library) inside a function) — the transaction **rolls back every write** and the flow takes `rolled_back`. UI actions queued by the rolled-back body (toasts, redirects) are **discarded** too, so a failed attempt leaves neither half-written data nor a misleading toast. The rollback message is exposed as `{{ vars.error }}`. This is the eval-free way to express an atomic multi-write operation — see the [worked checkout example](#worked-example-atomic-pos-style-checkout-no-eval).

### `http_request`

Call an external endpoint.

```json
{ "type": "http_request", "config": {
  "method": "post",
  "url": "https://api.example.com/hook",
  "headers": { "Authorization": "Bearer {{ states.api_key }}" },
  "body": { "name": "{{ input.name }}" },
  "output": "http"
} }
```

Writes the parsed JSON (or raw body) to `vars[output]` (default `http`) and the status code to `vars[output_status]`.

### `ai_invoke`

Call an AI integration through the gateway.

```json
{ "type": "ai_invoke", "config": {
  "integration": "summarize",
  "args": { "text": "{{ input.body }}" },
  "output": "ai"
} }
```

Writes the AI response text to `vars[output]` (default `ai`). Requires the AI gateway (via the [`AiInvoker`](extending.md#the-aiinvoker-contract) contract).

### `function`

Run a named [Function](functions-and-states.md).

```json
{ "type": "function", "config": {
  "function": "calc-total",
  "args": { "price": "{{ input.price }}" },
  "output": "result"
} }
```

Writes the return value to `vars[output]` (default `result`).

### `call_flow`

Run another saved flow's definition as a sub-step, sharing the caller's context (`vars`/`input`), so outputs flow between steps.

```json
{ "type": "call_flow", "config": {
  "flow": "calculate-discount",
  "output": "discount_result"
} }
```

- `flow` — the slug of the target flow to run. Its `definition` is executed as a nested sub-graph against the **same** `FlowContext`, so any vars the callee writes are visible to the caller's subsequent nodes.
- `output` — optional; when set, the callee's final `result` actions (or return value) are stored here.

**Cycle guard:** a flow cannot call itself directly or transitively (A→B→A is blocked). The call also counts against the shared `flow.max_steps` budget, so runaway call chains are bounded the same way as runaway loops.

This is the canonical way to factor a multi-step process used by more than one flow into a reusable unit — see [Functions & States → Compose, don't repeat](functions-and-states.md#compose-dont-repeat).

### `send_email`

Send an email via the [isolated mailer](email.md).

```json
{ "type": "send_email", "config": {
  "to": "{{ input.record.email }}",
  "subject": "Welcome {{ input.record.name }}",
  "template": "welcome-email",
  "cc": "", "bcc": "", "reply_to": "",
  "output": "email"
} }
```

`to` accepts a string (comma-separated) or array. `template` is the slug of a `kind=email` page whose HTML is interpolated against the flow context (mustache, not Alpine); use inline `body` instead if no template. Writes `{ "sent": true }` or `{ "sent": false, "error": "..." }` to `vars[output]` (default `email`).

### `result`

Append actions returned to the page runtime. **All action fields are interpolated.**

The `result` node uses a **low-code actions builder** in the editor: a type dropdown selects the action type and the relevant fields for that type appear automatically. Supported types:

| Type | Fields | Effect |
|---|---|---|
| `notify` | `message`, `level` (success/error/warning/info) | Toast notification |
| `alert` | `title`, `message` | Blocking alert dialog |
| `modal` | `action` (open/close), `target` (CSS selector), `html` (optional) | Open or close a modal |
| `redirect` | `url` | Navigate the browser |
| `setState` | `key`, `value` | Update a reactive State in `$store.app` |
| `setHtml` | `target` (CSS selector), `html` | Replace inner HTML of an element |
| `setText` | `target` (CSS selector), `text` | Replace text content of an element |
| `addClass` | `target` (CSS selector), `class` | Add a CSS class |
| `removeClass` | `target` (CSS selector), `class` | Remove a CSS class |
| `logout` | — | Sign the end-user out |

```json
{ "type": "result", "config": { "actions": [
  { "type": "notify",  "message": "Saved!", "level": "success" },
  { "type": "setHtml", "target": "#out", "html": "{{ vars.ai }}" },
  { "type": "setText", "target": ".count", "text": "{{ vars.count }}" },
  { "type": "redirect", "url": "/p/thanks" },
  { "type": "addClass", "target": "#box", "class": "done" },
  { "type": "removeClass", "target": "#box", "class": "loading" }
] } }
```

(The page runtime *also* understands `setState`/`setStates` when present in an actions array from any node.)

## Canvas tools

### Auto-layout

When the AI generates or updates a flow, or when you trigger **Auto-layout** from the toolbar, the canvas arranges all nodes so that no two overlap and edges are easy to follow. This is deterministic — calling it twice produces the same result.

### Zoom controls

The editor toolbar provides four zoom controls:

| Button | Action |
|---|---|
| Zoom out | Step down one zoom level |
| Reset zoom | Return to 100 % |
| Zoom in | Step up one zoom level |
| Fit to screen | Scale and pan so every node is visible |

### Editing transaction and loop bodies as step lists

Rather than editing a `transaction` or `loop` body as raw JSON, the canvas opens the body in a **step list** editor. Each step is one of:

| Step kind | What it does |
|---|---|
| **Function** | Run a named reusable expression function |
| **Flow** | Invoke a saved flow as a sub-step via `call_flow` |
| **Loop** | A for-each with its own `over`/`item_var` and a nested step list |
| **Node** | Any other node type — Record, Set state, Condition, HTTP request, Send email, AI invoke, Result — edited via its schema fields, with conditional field visibility |

Steps are ordered and sortable. A ⋮ kebab menu on each row provides **Reorder** (drag handle) and **Delete**. The step list compiles to and from the engine's `{ start, nodes }` sub-graph losslessly — the runtime is unchanged; only the editing surface is different. Editing an AI-generated flow in the step list and re-saving round-trips correctly.

## Worked example: atomic POS-style checkout (no eval)

`transaction` + `loop` + `record` + a few [helper-composing functions](functions-and-states.md#the-helper-library) express a real point-of-sale checkout — create the order, walk the cart asserting stock and decrementing it per line, then total the lines — **all-or-nothing and with no PHP/eval**. If any line runs out of stock mid-checkout, the order and every prior decrement roll back and the flow takes the `rolled_back` branch.

The four reusable functions (all `expression` runtime, composing `db_*` / `util_assert` helpers — see [Functions & States](functions-and-states.md#the-helper-library)):

| Function slug | Expression body |
|---|---|
| `assert-stock` | `util_assert(db_find('products', vars['item']['product_id'])['stock'] >= vars['item']['qty'], 'Insufficient stock')` |
| `decrement-stock` | `db_update('products', vars['item']['product_id'], {'stock': db_find('products', vars['item']['product_id'])['stock'] - vars['item']['qty']})` |
| `add-line` | `db_create('order_items', {'order_id': vars['order']['id'], 'product_id': vars['item']['product_id'], 'qty': vars['item']['qty'], 'subtotal': db_find('products', vars['item']['product_id'])['price'] * vars['item']['qty']})` |
| `order-total` | `db_update('orders', vars['order']['id'], {'total': db_aggregate('order_items', {'metric': 'sum', 'field': 'subtotal', 'filter': {'order_id': {'eq': vars['order']['id']}}})['total']})` |

The flow wraps the whole thing in a `transaction`. Its body creates the order, runs a `loop` over the cart (the loop's own body asserts stock → decrements → adds the line), then totals:

```json
{
  "start": "checkout",
  "nodes": {
    "checkout": {
      "type": "transaction",
      "committed": "ok",
      "rolled_back": "fail",
      "config": { "body": {
        "start": "mk_order",
        "nodes": {
          "mk_order": {
            "type": "record",
            "config": {
              "operation": "create", "model": "orders",
              "data": { "customer_name": "{{ input.customer_name }}", "total": 0, "status": "open" },
              "output": "order"
            },
            "next": ["lines"]
          },
          "lines": {
            "type": "loop",
            "config": {
              "over": "input.cart", "item_var": "item",
              "body": { "start": "assert", "nodes": {
                "assert": { "type": "function", "config": { "function": "assert-stock" }, "next": ["dec"] },
                "dec":    { "type": "function", "config": { "function": "decrement-stock" }, "next": ["line"] },
                "line":   { "type": "function", "config": { "function": "add-line" } }
              } }
            },
            "next": ["total"]
          },
          "total": { "type": "function", "config": { "function": "order-total" } }
        }
      } }
    },
    "ok":   { "type": "result", "config": { "actions": [ { "type": "notify", "message": "Order placed!", "level": "success" } ] } },
    "fail": { "type": "result", "config": { "actions": [ { "type": "notify", "message": "Checkout failed: out of stock.", "level": "error" } ] } }
  }
}
```

A cart of `[{product_id, qty}, …]` whose lines all have stock commits the order, decrements each product, totals the lines, and notifies "Order placed!". A cart with one over-quantity line fails the `assert-stock` guard mid-loop: the `loop` re-throws, the `transaction` rolls back the order **and** every earlier decrement (including the good lines'), and the flow takes the `fail` branch — leaving the database exactly as it was. (Mirrors `tests/Feature/InventoryPosTest.php` and `tests/Feature/FlowControlTest.php`.)

## FlowContext & interpolation

`src/Flow/FlowContext.php` carries a run's state: `input` (trigger data), `vars` (accumulated by nodes), `actions` (returned to the page), `steps` (telemetry).

Any string in a node config is interpolated with `{{ path }}` tokens (token pattern `[a-zA-Z0-9_.]+`), resolved against these roots:

| Token | Resolves to |
|---|---|
| `{{ input.x }}` | The trigger input |
| `{{ vars.x }}` | A variable a prior node wrote |
| `{{ states.x }}` | A persistent [State](functions-and-states.md) (loaded lazily from `VariableStore`) |
| `{{ globals.x }}` | Alias for `states` (backward compat) |

Dotted paths drill into arrays (`{{ input.record.email }}`, `{{ vars.records.total }}`). Scalars stringify; arrays JSON-encode. `interpolateDeep()` walks an entire config recursively.

## Run telemetry

Every run is recorded to `page_builder_flow_runs` (`FlowManager`): `flow_id`, `flow_slug_snapshot`, `trigger_type`, `status` (`ok`/`error`), `input`, `result` (`{actions, vars}`), `steps` (per-node `{node, type, status, error?}`), `error`, `duration_ms`.

Next: [Functions & States](functions-and-states.md).
