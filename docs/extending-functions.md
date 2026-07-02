# Extending: custom function helpers

[← Docs index](README.md) · see also [Extending](extending.md), [Flows](extending-flows.md), [Functions](functions-and-states.md)

The expression sandbox is open. A host app or third-party package can add its own **function helpers** — callables usable inside Function bodies and any expression — without forking Synapse. Anything you register shows up automatically in:

- the builder UI — the helper appears in the function-editor "Insert helper" dropdown, grouped by its category;
- the expression sandbox — the helper is callable by its `key` from any Function body / expression;
- the **capability catalogue** — `PageBuilder::capabilities()` and the `ai-page-builder:capabilities` command list it, which is the MCP/AI tool listing.

No core change is required.

For adding whole **flow nodes** (steps on the canvas) instead, see [Extending flow nodes](extending-flows.md).

## The registration seam

One facade method, meant to be called from a service provider's `boot()`:

```php
use Andre\AiPageBuilder\Facades\PageBuilder;

PageBuilder::registerHelper($definition, $fn);   // a CapabilityDefinition + callable
PageBuilder::capabilities();                      // read back the merged catalogue
```

It resolves to the `HelperRegistry` singleton, so register at boot time (before a flow runs or the function editor renders).

## Write & register a custom helper

A helper is a [`CapabilityDefinition`](../src/Capabilities/CapabilityDefinition.php) of kind `helper` paired with a callable. The definition's `key` is the name the helper is callable by inside the [expression sandbox](functions-and-states.md); the callable receives the helper's arguments positionally.

```php
use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Facades\PageBuilder;
use Illuminate\Support\Str;

PageBuilder::registerHelper(
    new CapabilityDefinition(
        key: 'util_slugify',                       // callable as util_slugify(...) in expressions
        label: 'util.slugify',
        category: CapabilityCategory::Util,
        kind: CapabilityDefinition::KIND_HELPER,
        description: 'Turns a string into a URL-safe slug.',
        usage: "util_slugify(input.title)",
        inputs: [
            new CapabilityInput('text', 'Text', 'string', required: true),
        ],
    ),
    static fn (string $text): string => Str::slug($text),
);
```

Put this call in a service provider's `boot()` (the same place as [node](extending-flows.md) and [component](extending-components.md) registration). A Function can then use it: `util_slugify(input.title)`.

For a whole group of related helpers, implement `Andre\AiPageBuilder\Capabilities\Helpers\HelperProvider` and call `PageBuilder::registerHelper(...)` for each inside its `register()` method — mirroring the core providers (`DbHelpers`, `UiHelpers`, `AuthHelpers`, `UtilHelpers`).

## It shows up everywhere, automatically

Once registered, a helper needs no further wiring:

- **Builder UI** — the helper appears in the function-editor "Insert helper" dropdown, grouped by its `category`.
- **Expression sandbox** — it is callable by its `key` from any Function body or expression.
- **Capability catalogue** — it appears in `PageBuilder::capabilities()` and in the `ai-page-builder:capabilities` JSON. See [MCP / AI exposure](#mcp--ai-exposure).

## The callable-`FunctionRegistry` path

There is a second, lighter path for a Function whose `runtime` is `callable`: the runtime looks its `body` up in the `FunctionRegistry`. Register callables at boot:

```php
use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Flow\FlowContext;

app(FunctionRegistry::class)->register(
    'slugify',
    fn (array $args, FlowContext $ctx): string => \Illuminate\Support\Str::slug((string) ($args['text'] ?? '')),
);
```

Signature: `callable(array $args, FlowContext $ctx): mixed`. Then create a Function with `runtime = callable` and `body = slugify`. See [Functions → callable runtime](functions-and-states.md#callable-runtime).

Which to use: register a **helper** (`PageBuilder::registerHelper`) when you want a named function usable *inline in any expression* and advertised in the capability catalogue; register a **callable Function** (`FunctionRegistry`) when you want a whole Function record whose implementation is PHP rather than an expression body.

## MCP / AI exposure

`PageBuilder::capabilities()` returns the merged catalogue — every node, helper, and component as an array. Helpers are the serialized [`CapabilityDefinition`](../src/Capabilities/CapabilityDefinition.php) (`toArray()`): `key`, `label`, `kind`, `category`, `category_label`, `category_order`, `description`, `usage`, `icon`, `inputs`, `output_handles`, `meta`. The shape is already **MCP-tool-shaped**, so an MCP server / tool layer can map it directly:

| capability field | MCP tool concept |
| --- | --- |
| `label` | tool name |
| `description` + `usage` | tool description / prose |
| `inputs` (`key`, `type`, `required`, `help`, …) | argument schema |
| `kind` | `node` vs `helper` vs `component` |
| `category`, `category_label` | grouping |

Dump it as JSON for an AI/MCP consumer:

```bash
php artisan ai-page-builder:capabilities          # compact JSON
php artisan ai-page-builder:capabilities --pretty # pretty-printed
```

Synapse does not ship a full MCP server — this catalogue is the seam. A thin MCP server (or any AI tool registry) reads this array and turns each entry into a tool descriptor; business logic stays in the registered helper.

---

Back to the [docs index](README.md).
