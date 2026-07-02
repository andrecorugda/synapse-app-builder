# Extending

[← Docs index](README.md)

Synapse is built to be extended from the host app: swap models, add flow node types, function helpers and editor blocks, plug in your own AI invoker, and react to events. None of this requires forking the package.

This page is the **overview** — the cross-cutting model and the seams shared by every extension. The detailed how-tos live in three sub-pages:

## In this section

- **[Flows](extending-flows.md)** — register a custom **flow node** (a step on the canvas): implement `FlowNodeHandler` + `ProvidesNodeDefinition`, register via `PageBuilder::registerNode()`.
- **[Functions](extending-functions.md)** — register a custom **function helper** (a callable in the expression sandbox): a `CapabilityDefinition` (kind `helper`) + a callable, registered via `PageBuilder::registerHelper()`; plus the callable-`FunctionRegistry` path.
- **[Components](extending-components.md)** — register a custom **draggable block**: a `SectionBlock`, registered via `PageBuilder::registerComponent()`. Also the seam for open-core **premium component packs**.

## The capability-registry model

Nodes, helpers and components all follow the same shape: a small registry the package seeds with its built-ins and a host app / third-party package adds to from a service provider's `boot()`. Register once and the addition surfaces everywhere — the builder UI, the validator, and the machine-readable capability catalogue — with **no core change**.

- **Nodes** → `NodeRegistry`, via `PageBuilder::registerNode()`.
- **Helpers** → `HelperRegistry`, via `PageBuilder::registerHelper()`.
- **Components** → `ComponentRegistry`, via `PageBuilder::registerComponent()`.

Nodes and helpers describe themselves with one shared value object, [`CapabilityDefinition`](../src/Capabilities/CapabilityDefinition.php) — the same class, differing only by `kind` (`KIND_NODE` / `KIND_HELPER`) — which is why a single `PageBuilder::capabilities()` call returns them together. Components are `SectionBlock`s and are mapped into that same catalogue as `kind: 'component'`.

## The `PageBuilder` facade

The package registers a `PageBuilder` facade (auto-aliased via `composer.json` → `extra.laravel.aliases`), fronting [`PageBuilderManager`](../src/Services/PageBuilderManager.php). It exposes page rendering plus the whole extensibility seam:

```php
use Andre\AiPageBuilder\Facades\PageBuilder;

$html = PageBuilder::render($page);   // fully-rendered (cached) HTML for a published Page
PageBuilder::forget($page->slug);      // bust the render cache for a slug

PageBuilder::registerNode($handler);              // add a custom flow node        → Flows
PageBuilder::registerHelper($definition, $fn);    // add a custom function helper   → Functions
PageBuilder::registerComponent($block);           // add a custom draggable block   → Components

PageBuilder::components();     // the serialized block list for the GrapesJS block manager
PageBuilder::capabilities();   // the merged node + helper + component catalogue (MCP/AI tool list)
```

Each `register*` call is documented on its sub-page above. For data, flows and AI, use the dedicated services directly (`RecordQuery`, `FlowManager`, `BuildPlanApplier`, etc.) as shown throughout these docs.

## Swapping a model

Every model is resolved through `config('ai-page-builder.models.*')` (see [Configuration](configuration.md#models)), so you can subclass one and point the config at your class to add behavior:

```php
// app/Models/AppPage.php
namespace App\Models;

use Andre\AiPageBuilder\Models\Page as BasePage;

class AppPage extends BasePage
{
    protected static function booted(): void
    {
        static::saved(fn (self $page) => /* your hook */);
    }
}
```

```php
// config/ai-page-builder.php
'models' => [
    'page' => \App\Models\AppPage::class,
    // …
],
```

The services, controllers, the AI applier and the Filament resources all read the class from config, so your subclass is used everywhere. Keep the table columns and route-key contract intact (e.g. `Page` resolves by `slug`).

## The `AiInvoker` contract

The flow engine and the AI app builder don't hard-depend on the gateway — they depend on `Andre\AiPageBuilder\Flow\Contracts\AiInvoker`:

```php
interface AiInvoker
{
    public function available(): bool;

    /**
     * @param array<string,mixed> $args     values for the integration prompt placeholders
     * @param array<int,array<string,mixed>> $messages conversation turns (role/content)
     * @param array<string,mixed> $opts      per-call options
     */
    public function invoke(string $integration, array $args = [], array $messages = [], array $opts = []): string;
}
```

The default binding is `GatewayAiInvoker` (routes through the AI OpenRouter Gateway when installed; throws if not). Bind your own to use a different backend — or a fake in tests:

```php
use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;

$this->app->bind(AiInvoker::class, \App\Ai\MyInvoker::class);
```

Your implementation backs both the [`ai_invoke` flow node](flows.md#ai_invoke) and the [`AppBuilderService`](ai.md) (which calls `invoke($app_builder_slug, ['app_context' => …], $conversation)` and expects the model's reply text back).

## Events & observers

### Collection writes → flows

Every collection record write goes through the dynamic `Record` model, which the package observes with `RecordObserver` (registered in `packageBooted()`). On `created`/`updated`/`deleted` it forwards `{ event, collection, record }` to `FlowDispatcher::dispatchCollectionEvent()`, which fans out to matching [collection-triggered flows](flows.md#collection-trigger). Re-attached each boot (Eloquent ties observers to the event dispatcher, which is fresh per app instance).

To react to collection writes in your **own** code, observe the same model:

```php
use Andre\AiPageBuilder\Models\Record;

Record::observe(\App\Observers\MyRecordObserver::class);
```

`$record->pbModelKey` tells you which collection the row belongs to. (Re-register on each boot for the same reason the package does.)

### Standard model events

The package's own models (`Page`, `Flow`, etc.) are ordinary Eloquent models — hook their lifecycle events as usual (e.g. via a subclass per [Swapping a model](#swapping-a-model), or `Page::saved(...)`). The render cache is already busted on page save/delete by the package.

---

That's the overview. Continue with [Flows](extending-flows.md), [Functions](extending-functions.md), or [Components](extending-components.md) — or back to the [docs index](README.md).
