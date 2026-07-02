# Functions & States

[← Docs index](README.md)

Two small but central pieces: **Functions** are reusable units of logic a flow can call; **States** are persistent, app-wide variables that pages bind to reactively and flows update live.

## Functions

A function is a row in `page_builder_functions` (`src/Models/FlowFunction.php`), edited under **Functions** in the admin and invoked by the [`function` flow node](flows.md#function). Route key `slug`; soft-deletes.

| Column | Notes |
|---|---|
| `slug` | `^[a-z][a-z0-9_-]*$` |
| `name` | Label |
| `description` | Optional |
| `runtime` | `expression` \| `callable` \| `php` |
| `body` | Code/expression/key, depending on runtime |

The `function` node passes interpolated `args` and writes the return value to `vars[output]`.

### `expression` runtime — the recommended, eval-free path

A [Symfony ExpressionLanguage](https://symfony.com/doc/current/components/expression_language.html) expression — a safe sandbox with **no raw PHP and no eval**. Its only callable surface is `state()`/`global()` plus the curated [helper library](#the-helper-library) (`db_*`, `ui_*`, `auth_*`, `util_*`). This is the **recommended way to express logic**: a function *composes documented, allow-listed helpers* rather than executing arbitrary code, so it gets real power (read/write collections, pop toasts, guard business rules) while staying safe. See the [syntax reference](https://symfony.com/doc/current/reference/formats/expression_language.html) for operators, literals and indexing.

The expression sees these variables:

- `input` — the flow input
- `vars` — accumulated flow vars
- `args` — the node's `args`
- `states` / `globals` — the persistent State map

Plus `state('key')` / `global('key')` (alias) to read a persistent State, and every helper in the [helper library](#the-helper-library).

```text
args["price"] * 1.2
```

```text
state('tax_rate') * args["subtotal"]
```

```text
db_create('orders', {customer_id: auth_id(), total: vars.total, ref: util_uuid()})
```

**Errors propagate.** When a function runs (the [`function` node](flows.md#function) uses `ExpressionEvaluator::evaluateOrThrow`), a failing or asserting expression **throws** — it surfaces to the engine so it isn't silently masked, and a wrapping [Transaction](flows.md#transaction) can roll back. (The swallowing `evaluate()` variant — returns `null` and logs on error — is still used for config interpolation, not for running functions.) To make a multi-write function atomic, run it inside a [`transaction` node](flows.md#transaction); to abort it on a business rule, use [`util_assert`](#the-helper-library).

### `callable` runtime

`body` is a key into the `FunctionRegistry`. You register a PHP callable at boot (see [Extending](extending.md#registering-a-callable-function)):

```php
app(\Andre\AiPageBuilder\Flow\FunctionRegistry::class)
    ->register('calc-total', fn (array $args, \Andre\AiPageBuilder\Flow\FlowContext $ctx): float
        => (float) $args['price'] * 1.2);
```

The callable signature is `callable(array $args, FlowContext $ctx): mixed`.

### `php` runtime — and the security flag

`body` is raw PHP executed in a closure with `$args`, `$input`, `$vars`, `$states`, `$globals` in scope. Inside it you can read/write States, query collections, etc.:

```php
$total = 0;
foreach ($args['items'] as $item) {
    $total += $item['price'];
}
app(\Andre\AiPageBuilder\Services\Data\VariableStore::class)->set('cart_total', $total);
return $total;
```

> **Security: `php` is OFF by default.** The `php` runtime runs **arbitrary PHP**, so it is gated by `flow.allow_php_functions` (`AI_PAGE_BUILDER_ALLOW_PHP`, default **`false`**). With it off, the `php` option is hidden from the Function form and any `php` function is refused at run time (returns `null`). **The recommended power model is eval-free**: an [`expression`](#expression-runtime--the-recommended-eval-free-path) function composing the [helper library](#the-helper-library) covers data writes, UI feedback, auth and utilities without arbitrary code. Only opt in to `php` (`AI_PAGE_BUILDER_ALLOW_PHP=true`) on a single-tenant, self-hosted install where the function author *is* the app owner — never when less-trusted authors can edit functions.

## The helper library

The expression sandbox ships with a curated, **eval-free** set of callables — the helper library — so a Function can read and write data, give UI feedback, read the user, and shape values without raw PHP. They are grouped by category and surface in the function editor's **ƒ Insert helper…** dropdown (each entry shows its description and usage), and they're the same callables an MCP/AI layer sees in the [capability catalogue](extending-flows.md#mcp--ai-exposure). Call a helper by its `name` inside any `expression` function.

| Helper | Category | Description | Usage |
|---|---|---|---|
| `db_create` | Data | Create a record in a collection; returns the created row. | `db_create('orders', {customer_id: 1, total: 99.5})` |
| `db_update` | Data | Update a record by id; returns the updated row (or null if missing). | `db_update('orders', 42, {status: 'paid'})` |
| `db_delete` | Data | Delete a record by id; returns true on success. | `db_delete('cart_items', 7)` |
| `db_find` | Data | Fetch a single record by id; returns the row or null. | `db_find('products', input.product_id)` |
| `db_list` | Data | List records with optional Directus-style params (filter/sort/search/per_page); returns rows. | `db_list('orders', {filter: {status: {eq: 'open'}}, sort: '-created_at'})` |
| `db_aggregate` | Data | Aggregate a column (sum/count/avg/min/max), optionally grouped; returns `{metric, total, rows}`. | `db_aggregate('order_items', {metric: 'sum', field: 'subtotal', filter: {order_id: {eq: 42}}})` |
| `ui_notify` | UI & Feedback | Show a toast notification. | `ui_notify('Order placed!', 'success')` |
| `ui_alert` | UI & Feedback | Show a blocking alert dialog with a title and message. | `ui_alert('Out of stock', 'This item just sold out.')` |
| `ui_modal` | UI & Feedback | Open or close a modal by selector; optionally set its inner HTML. | `ui_modal('open', '#checkout-modal')` |
| `ui_redirect` | UI & Feedback | Navigate the browser to a URL. | `ui_redirect('/p/order-confirmation')` |
| `ui_logout` | Auth & Access | Sign the current end-user out and redirect to login. | `ui_logout()` |
| `ui_set_state` | UI & Feedback | Push a value into the reactive store (`$store.app`) so bound components re-render. | `ui_set_state('cart_total', vars.total)` |
| `auth_user` | Auth & Access | The signed-in end-user as an array (sensitive columns hidden), or null when a guest. | `auth_user()` |
| `auth_id` | Auth & Access | The signed-in end-user id, or null when a guest. | `auth_id()` |
| `auth_check` | Auth & Access | True when an end-user is signed in. | `auth_check()` |
| `util_now` | Utilities | The current date/time with a PHP date format (default `Y-m-d H:i:s`). | `util_now('Y-m-d')` |
| `util_uuid` | Utilities | A new random UUID v4 string. | `util_uuid()` |
| `util_number_format` | Utilities | Format a number with a fixed number of decimals. | `util_number_format(vars.total, 2)` |
| `util_json_encode` | Utilities | Encode a value to a JSON string. | `util_json_encode(vars.payload)` |
| `util_json_decode` | Utilities | Decode a JSON string to a value. | `util_json_decode(input.body)` |
| `util_assert` | Utilities | Enforce a precondition — if the condition is falsey, **fail the step** with the given message. | `util_assert(db_find('products', vars.item.id)['stock'] >= vars.item.qty, 'Insufficient stock')` |

Notes:

- **`db_*`** route through the same `RecordQuery` layer the [Record node](flows.md#record) and REST API use. They are author-trusted (full data access, like the Record node); the REST API stays the permission-enforced edge. Wrap writing helpers in a [Transaction](flows.md#transaction) for all-or-nothing behaviour.
- **`ui_*`** queue a browser action onto the running flow (the same channel the [Result node](flows.md#result) uses, via `FlowRuntime`). Outside a flow run they are graceful no-ops.
- **`util_assert`** is the business-rule guard: a falsey condition (`false`/`null`/`0`/`''`/`[]`) throws with your message. Because function errors propagate, that aborts the step — and inside a `transaction` it rolls everything back. It is the linchpin of the eval-free [atomic checkout](flows.md#worked-example-atomic-pos-style-checkout-no-eval).

To add your own helper, see [Extending flow nodes & helpers](extending-flows.md#c-write--register-a-custom-helper) — registered helpers appear in the dropdown and the catalogue automatically.

## States

A State is a persistent, app-wide variable in `page_builder_variables` (`src/Models/Variable.php`). The Filament resource is labelled **States**; the model is `Variable`. Route key `key`; the `VariableStore` service is the read/write API.

| Column | Notes |
|---|---|
| `key` | `^[a-z][a-z0-9_]*$`. **Immutable after create.** |
| `type` | `string` \| `number` \| `boolean` \| `json` |
| `value` | Stored as a string; cast back on read per `type` |
| `description` | Optional |
| `is_protected` | Guards against casual edit/delete (advisory) |

Type round-tripping: `number` → int/float, `boolean` → `1`/`0` ↔ bool, `json` → `json_encode`/`json_decode`, `string` → as-is.

### Reading & writing

**`VariableStore`** (`src/Services/Data/VariableStore.php`) — a memoized singleton (the full map is loaded once per request and the cache is flushed on every write):

```php
$store = app(\Andre\AiPageBuilder\Services\Data\VariableStore::class);

$store->get('tax_rate', 0.0);          // typed value, with default
$store->set('tax_rate', 0.2);          // infers type when omitted
$store->set('flags', ['beta' => true], 'json');
$store->has('tax_rate');
$store->forget('tax_rate');
$store->all();                          // key => typed value
```

From flows: the [`set_variable` node](flows.md#set_variable) writes a State; any `{{ states.x }}` token reads one. From expression functions: `state('x')`.

### The reactive Store (data binding on pages)

When a page renders, all States are serialized and seeded into an Alpine store named `app`:

```js
window.__pbState = { /* every State, key => value */ };
Alpine.store('app', { ...window.__pbState });
```

Page markup binds to it declaratively over `$store.app.<key>` — `x-text`, `x-show`, `x-model`, `x-for` (see [Pages → declarative binding](pages-and-components.md#declarative-data-binding-alpine)). The current end-user is added at `$store.app.$user` (from `GET /pb-auth/me`).

A flow can update the store **live** without a reload: a `result` action of type `setState`/`setStates` (applied by the page runtime) writes into `$store.app`, and every bound component re-renders. (Persisting the value server-side is a separate `set_variable` node — `setState` only changes the in-page store for that visitor.)

## Compose, don't repeat

There are two levels of reuse:

- **A single expression** that's used in multiple places → factor it into a **Function** and call it with the [`function` node](flows.md#function). A function is a named one-liner (or short expression) that stays readable and testable in isolation.
- **A multi-step process** needed by more than one flow → make it its own **Flow** and invoke it with the [`call_flow` node](flows.md#call_flow). The called flow runs with the caller's shared context (`vars`/`input`), so outputs flow through naturally.

The AI generator applies the same principle: it will factor logic that appears in more than one place into a function or a shared flow, but it won't over-factor a one-off step that only exists in one context.

Next: [Authentication & permissions](authentication-and-permissions.md).
