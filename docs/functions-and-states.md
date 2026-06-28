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

### `expression` runtime

A [Symfony ExpressionLanguage](https://symfony.com/doc/current/components/expression_language.html) expression — a safe sandbox (no PHP functions, no eval, no file/DB/network access; pure expressions only). The expression sees these variables:

- `input` — the flow input
- `vars` — accumulated flow vars
- `args` — the node's `args`
- `states` / `globals` — the persistent State map

Plus two custom helpers: `state('key')` and `global('key')` (alias) read a persistent State. Errors never throw — `ExpressionEvaluator` logs a warning and returns `null`.

```text
args["price"] * 1.2
```

```text
state('tax_rate') * args["subtotal"]
```

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

> **Security:** the `php` runtime runs **arbitrary PHP**. It is gated by `flow.allow_php_functions` (`AI_PAGE_BUILDER_ALLOW_PHP`, default **true** in config). This is intentional for a single-tenant, self-hosted builder where the function author *is* the app owner. **Set it to `false` if you ever expose the builder to less-trusted authors** — the `php` option then disappears from the Function form and `php` functions are refused at run time. Prefer `expression` or `callable` for anything you don't fully control.

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

Next: [Authentication & permissions](authentication-and-permissions.md).
