# Flows

[← Docs index](README.md)

A **flow** is the automation engine — an n8n-style graph of nodes that runs in response to a trigger. A flow is a row in `page_builder_flows`; its graph lives in the `definition` column. The visual canvas (Drawflow) edits it; `FlowRunner` executes it; `FlowManager` records each run to `page_builder_flow_runs`.

## The Flow model

`src/Models/Flow.php`. Route key `slug`; soft-deletes; scope `active()` (where `is_active = true`).

| Column | Type | Notes |
|---|---|---|
| `slug` | string | Route key, `^[a-z0-9\-_]+$` |
| `name` | string | |
| `trigger_type` | string | `manual` \| `component` \| `collection` \| `cron` \| `api` (the Filament form also offers `form`) |
| `is_active` | bool | Inactive flows never run |
| `is_public` | bool | Required for the public run endpoint (unauthenticated trigger) |
| `rate_limit_per_minute` | int\|null | Per-flow override of the global rate limit |
| `trigger_config` | array (JSON) | Trigger-specific config (see below) |
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

`FlowRunner` starts at `definition.start`, looks up each node's handler in the `NodeRegistry` by `type`, runs it, and enqueues the node ids the handler returns (`next`, or `next_true`/`next_false` for a condition). It records a step per node into the run telemetry and stops at an empty queue or `flow.max_steps` (200).

## Triggers

Set on the flow's `trigger_type` + `trigger_config`.

### Manual / component / form

Run synchronously — from the admin (manual) or from a page interaction. On a page, an element with `data-pb-flow="<slug>"` runs the flow on its `data-pb-flow-event` (default click/submit); the nearest `<form>`'s fields plus any `data-pb-flow-input` JSON become the flow `input`. The flow must be `is_active` **and** `is_public`. See [Pages → runtime attributes](pages-and-components.md#runtime-data-attributes).

### Collection trigger

Fires when a collection record is written. `FlowDispatcher` observes every collection write (via `RecordObserver` on the dynamic `Record` model) and fans out to matching flows.

```json
{
  "collection": "signups",
  "events": ["created", "updated", "deleted"],
  "criteria": { "status": { "eq": "active" } }
}
```

- `collection` — the collection key to watch.
- `events` — any of `created`, `updated`, `deleted`.
- `criteria` — optional; the changed record must satisfy **all** conditions (AND). Same operator set as `RecordQuery` filters (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `nin`, `null`, `nnull`); a bare scalar means `eq`.

The flow receives `input = { "event": "...", "collection": "...", "record": { ...the row... } }`, so a node reads the changed field as `{{ input.record.<field> }}`. A re-entrancy guard (`MAX_DEPTH = 3`) prevents infinite loops when a flow writes to its own triggering collection; dispatch never throws (failures are logged) so the originating DB write always succeeds.

### Cron trigger

Runs on a schedule you control. Schedule the command (see [Installation](installation.md#5-schedule-cron-flows-optional)):

```bash
php artisan ai-page-builder:run-cron-flows
```

It runs every active flow with `trigger_type = cron` (empty input), isolating per-flow failures.

### API trigger

Run over HTTP via the public endpoint below.

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

### `trigger`

Entry node. No config — hands off to `next`. Every flow's `start` should be a trigger.

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

The `result` node accepts exactly these action types: `setHtml`, `setText`, `notify`, `redirect`, `addClass`, `removeClass`. (The page runtime *also* understands `setState`/`setStates` when present in an actions array, but the `result` node only emits the six above.)

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
