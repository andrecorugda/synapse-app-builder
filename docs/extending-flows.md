# Extending: custom flow nodes

[← Docs index](README.md) · see also [Extending](extending.md), [Functions](extending-functions.md), [Flows](flows.md)

The flow engine is open. A host app or third-party package can add its own **flow nodes** — steps on the canvas — without forking Synapse. Anything you register shows up automatically in:

- the builder UI — the node appears in the canvas drawer, grouped by its category;
- the validator and the AI app builder — a registered node `type` is a valid node;
- the **capability catalogue** — `PageBuilder::capabilities()` and the `ai-page-builder:capabilities` command — which is the MCP/AI tool listing.

No core change is required.

For adding **function helpers** (callables in the expression sandbox) instead, see [Extending function helpers](extending-functions.md). For adding **components** (draggable editor blocks), see [Extending components](extending-components.md).

## The registration seam

One facade method, meant to be called from a service provider's `boot()`:

```php
use Andre\AiPageBuilder\Facades\PageBuilder;

PageBuilder::registerNode($handler);   // a FlowNodeHandler instance
PageBuilder::capabilities();            // read back the merged catalogue
```

It resolves to the `NodeRegistry` singleton, so register at boot time (before a flow runs or the drawer renders).

## (a) Write a custom node

A node type is a class implementing `FlowNodeHandler`. Implement `ProvidesNodeDefinition` too so it carries its own drawer/MCP metadata (label, category, description, usage, typed inputs, output handles). A handler **without** a definition still works — the registry synthesizes a minimal entry so it stays discoverable — but a definition is what makes it first-class.

```php
namespace App\Flow;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;
use Illuminate\Support\Str;

class SlugifyNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    /** The node's `type` in a flow definition. */
    public function type(): string
    {
        return 'slugify';
    }

    /**
     * @param  array<string,mixed>  $node  the node def: { type, config, next }
     * @return array<int,string>           next node ids
     */
    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);

        // Interpolate {{ ... }} placeholders in the configured text.
        $text = (string) $context->interpolateDeep((string) ($config['text'] ?? ''));
        $output = (string) ($config['output'] ?? 'slug');

        // Do the work, then expose the result to downstream nodes.
        $context->set($output, Str::slug($text));

        // Hand control to whatever this node connects to.
        return (array) ($node['next'] ?? []);
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Slugify',
            category: CapabilityCategory::Util,
            description: 'Turns a string into a URL-safe slug and stores it in a context variable.',
            usage: 'text "{{ input.title }}", output "slug" → exposes {{ vars.slug }} downstream.',
            icon: 'link',
            inputs: [
                new CapabilityInput('text', 'Text', 'expression', required: true, help: 'The string to slugify (interpolated).'),
                new CapabilityInput('output', 'Context variable', 'string', default: 'slug', help: 'Context var to receive the slug.'),
            ],
            outputHandles: ['next'],
        );
    }
}
```

`run()` reads `config` (interpolate strings via `$context->interpolate()` / `interpolateDeep()`), does its work, optionally `$context->set('<output>', $value)` or `$context->addAction([...])`, and returns the next node ids. The `outputHandles` in the definition are the connection points the canvas draws (`['next']` for a single successor; a branching node might declare `['next_true', 'next_false']`).

## (b) Register the node from a service provider

```php
namespace App\Providers;

use Andre\AiPageBuilder\Facades\PageBuilder;
use App\Flow\SlugifyNode;
use Illuminate\Support\ServiceProvider;

class FlowExtensionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PageBuilder::registerNode(new SlugifyNode);
    }
}
```

If your node has constructor dependencies, resolve it from the container: `PageBuilder::registerNode(app(SlugifyNode::class));`.

## (c) It shows up everywhere, automatically

Once registered, a node needs no further wiring:

- **Builder UI** — the node appears in the canvas drawer, grouped by its `category`, with its declared inputs and output handles.
- **Validation & AI** — a registered node `type` is valid in the BuildPlan validator and described to the AI app builder.
- **Capability catalogue** — it appears in `PageBuilder::capabilities()` and in the `ai-page-builder:capabilities` JSON. See [MCP / AI exposure](#mcp--ai-exposure).

## MCP / AI exposure

`PageBuilder::capabilities()` returns the merged catalogue — every node, helper, and component as an array. Nodes are the serialized [`CapabilityDefinition`](../src/Capabilities/CapabilityDefinition.php) (`toArray()`): `key`, `label`, `kind`, `category`, `category_label`, `category_order`, `description`, `usage`, `icon`, `inputs`, `output_handles`, `meta`. The shape is already **MCP-tool-shaped**, so an MCP server / tool layer can map it directly:

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

Synapse does not ship a full MCP server — this catalogue is the seam. A thin MCP server (or any AI tool registry) reads this array and turns each entry into a tool descriptor; business logic stays in the registered node.

---

Back to the [docs index](README.md).
