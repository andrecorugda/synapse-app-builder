# Watchers

A **watcher** reacts to a change and runs a flow (or function). It binds **one
source event → one target**, decoupled from the flow itself — so a single
reusable flow can be triggered from many places, and each event can run a
*different* flow. Watchers live in the `watchers` table and are managed under
**Automation → Watchers** in the admin.

Two kinds of source:

- **Collection** — a record in one of your [Collections](collections-and-data.md)
  was created, updated or deleted.
- **State** — a global [State](functions-and-states.md) (reactive variable)
  changed, either server-side (persisted) or in the browser (live page state).

> Watchers are why `trigger_type` on a flow is now just a **label**. A flow is a
> reusable graph; *what* fires it lives elsewhere: page interactions and the API
> use the [public run endpoint](flows.md#the-public-run-endpoint) (governed by
> Public/Private), collection & state events use watchers, and cron uses
> [Schedules](configuration.md). See [Flows → triggers](flows.md#triggers).

## The shape

| Column | Meaning |
|---|---|
| `name` | Human label. |
| `source_type` | `collection` or `state`. |
| `source_key` | Collection key, or State (variable) key. |
| `event` | `created` \| `updated` \| `deleted` (collection) or `changed` (state). One event per watcher. |
| `config` | JSON — conditions (see below). |
| `target_type` | `flow` or `function`. |
| `target_key` | The flow/function **slug** to run. |
| `is_active` | Only active watchers fire. |
| `last_fired_at` / `last_status` / `last_error` | Per-watcher telemetry, stamped on each run. |

Dispatch is bounded by a re-entrancy guard (`MAX_DEPTH = 3`) so a flow that
writes back to its own triggering collection/state can't loop without bound, and
it never throws — a misbehaving target is logged, and the originating write
always succeeds.

## Collection watchers

One watcher = one collection + one event → one target. To run different logic on
create vs update vs delete, make separate watchers. This is the key difference
from the old single-graph collection trigger: **created → Flow A, updated →
Flow B, deleted → Flow C** is now natural.

The target receives:

```json
{ "event": "updated", "collection": "signups", "record": { "…the new row…" }, "old": { "…the previous row…" } }
```

so a node reads a changed field as `{{ input.record.<field> }}` and the prior
value as `{{ input.old.<field> }}` (`old` is empty on `created`).

**Conditions** (all optional, under `config`):

- **Criteria** — the changed record must satisfy **all** rules (AND). Same
  operator set as [RecordQuery filters](collections-and-data.md)
  (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `nin`, `null`, `nnull`);
  stored as `{ field: { op: value } }`.
- **Changed fields** — on `updated`, fire only when one of the named fields
  actually changed (old ≠ new). Ignored for `created`/`deleted`.

## State watchers

Fire when a global State changes. Choose **where** it's watched:

- **Server** (default) — fires when the persisted value changes via
  `VariableStore::set` (which the `set_variable` flow node and the data layer go
  through). Server watchers can target a flow **or** a function.
- **Browser** — watches the page's **live** `$store.app` on rendered pages as
  the visitor interacts (typing, selections, cart edits) — like a JS-framework
  watcher. Browser watchers target **flows only** (they fire through the public
  run endpoint).

The target receives `{ "event": "changed", "key": "<state>", "old": …, "new": … }`
(plus the live store for browser watchers).

**Conditions** (all optional, under `config`):

- **Path** — for an [Object-shape State](functions-and-states.md), watch a single
  dotted sub-path (e.g. `customer.city`) instead of the whole value. The admin
  offers the shape's flattened paths as a dropdown.
- **From → To** — fire only on a specific transition (previous == `from`,
  new == `to`).
- **Operator + value** — test the new value with an operator (same set minus
  `null`/`nnull`).

### Browser runtime semantics

Rendered pages embed their active browser-side watchers and install one reactive
effect per watcher:

- **Debounce** — a burst of changes (typing into an `x-model` input) fires the
  flow **once**, after it settles (~300 ms); `old` is the value from *before* the
  burst.
- **Loop guard** — a `setState` returned by the fired flow won't re-trigger the
  same watcher (a 500 ms suppression window), so a flow that rewrites its own
  watched key can't loop. A later genuine user edit fires again normally.
- Conditions (path / from→to / operator) are evaluated client-side with the same
  semantics as the server.

Because rendered pages carry the watcher list and page HTML is cached, saving or
deleting a watcher flushes the render cache.

## Test-fire & runs

The watcher edit page has a **Test fire** action that runs the target once with a
representative payload (bypassing conditions) and reports the outcome — useful for
wiring checks. Runs the watcher caused are listed on its **Runs** tab
(each is a `page_builder_flow_runs` row tagged with the watcher).

## Export / import & AI

Watchers travel with the app: [app export/import](ai.md) carries a `watchers`
section, and the AI build-plan contract can author them directly. When an older
plan (or a legacy flow) still uses `trigger_type: collection` + `trigger_config`,
applying it **materializes the equivalent watchers automatically**, so nothing
silently stops firing.
