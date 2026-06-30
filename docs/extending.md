# Extending

[← Docs index](README.md)

Synapse is built to be extended from the host app: swap models, add flow node types and editor blocks, plug in your own AI invoker, and react to events. None of this requires forking the package.

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

## Registering a custom flow node

A node type is any class implementing `Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler`:

```php
namespace App\Flow;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\FlowContext;

class SlackNotifyNode implements FlowNodeHandler
{
    public function type(): string
    {
        return 'slack_notify';   // the node's `type` in a flow definition
    }

    /**
     * @param  array<string,mixed>  $node   the node def: { type, config, next }
     * @return array<int,string>            next node ids
     */
    public function run(array $node, FlowContext $context): array
    {
        $config  = (array) ($node['config'] ?? []);
        $message = $context->interpolate((string) ($config['message'] ?? ''));

        // … send to Slack …

        $context->set((string) ($config['output'] ?? 'slack'), ['sent' => true]);

        return (array) ($node['next'] ?? []);
    }
}
```

Register it with the `NodeRegistry` singleton (e.g. in a service provider's `boot()`):

```php
use Andre\AiPageBuilder\Flow\NodeRegistry;

$this->app->resolving(NodeRegistry::class, function (NodeRegistry $registry) {
    $registry->register(new \App\Flow\SlackNotifyNode);
});
```

`run()` should: read `config` (interpolating strings via `$context->interpolate()` / `interpolateDeep()`), do its work, optionally `$context->set('<output>', $value)` or `$context->addAction([...])`, and return the next node ids. The new type immediately becomes valid in the [`BuildPlanValidator`](ai.md#validation) and is described to the AI by [`SystemPromptBuilder`](ai.md#the-code-generated-system-prompt) (add a `NODE_HINTS` entry by extending that class if you want a tailored prompt hint; unknown types still appear with a generic hint).

> The simpler, modern path is the `PageBuilder::registerNode($handler)` facade (it resolves to this same `NodeRegistry`). Implement `ProvidesNodeDefinition` alongside `FlowNodeHandler` and your node carries its own drawer/MCP metadata (label, category, typed inputs, output handles) and shows up in the canvas drawer and the [capability catalogue](extending-flows.md#mcp--ai-exposure) automatically. See **[Extending flow nodes & helpers](extending-flows.md)** for the full walkthrough — that page is the canonical reference for nodes *and* function helpers.

## Registering a callable Function

The `callable` Function runtime looks its `body` up in the `FunctionRegistry`. Register callables at boot:

```php
use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Flow\FlowContext;

app(FunctionRegistry::class)->register(
    'slugify',
    fn (array $args, FlowContext $ctx): string => \Illuminate\Support\Str::slug((string) ($args['text'] ?? '')),
);
```

Signature: `callable(array $args, FlowContext $ctx): mixed`. Then create a Function with `runtime = callable` and `body = slugify`. See [Functions](functions-and-states.md#callable-runtime).

## Custom blocks

The editor's blocks come from `Andre\AiPageBuilder\Blocks\BlockVocabulary` (`SectionBlock` value objects: `key`, `label`, `category`, `template`, `description`, `icon`). The block list is consumed by the GrapesJS block manager, by the AI system prompt (section keys only) and by the validator's known-block set.

`BlockVocabulary` is a `final` class with static methods, so the supported extension point is to provide your block definitions to the editor field configuration / the JS block manager rather than mutating the class. Two practical paths:

- **Compose with the published set** — read `BlockVocabulary::toArray()` (the serialized form) in your panel/editor wiring and append your own block descriptors (same `{key,label,category,template,description}` shape) before handing them to GrapesJS.
- **Use the `Basic` family** — primitive blocks (`text`, `heading`, `image`, `button`, `columns-*`, `spacer`, `divider`) need no `data-pb-block` convention, so custom free-form composition works out of the box.

Keep the `data-pb-block="<key>"` convention on any block you want imported as a labelled, editable component and recognized by the validator. See [Pages → block vocabulary](pages-and-components.md#the-block-vocabulary).

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

## The `PageBuilder` facade

The package registers a `PageBuilder` facade (auto-aliased via `composer.json` → `extra.laravel.aliases`), fronting `PageBuilderManager`. It exposes page rendering plus the extensibility seam:

```php
use Andre\AiPageBuilder\Facades\PageBuilder;

$html = PageBuilder::render($page);   // fully-rendered (cached) HTML for a published Page
PageBuilder::forget($page->slug);      // bust the render cache for a slug

PageBuilder::registerNode($handler);             // add a custom flow node
PageBuilder::registerHelper($definition, $fn);   // add a custom function helper
PageBuilder::capabilities();                      // the merged node+helper catalogue (MCP/AI tool list)
```

See [Extending flow nodes & helpers](extending-flows.md) for the full node/helper registration walkthrough and the MCP/AI capability catalogue.

For data, flows and AI, use the dedicated services directly (`RecordQuery`, `FlowManager`, `BuildPlanApplier`, etc.) as shown throughout these docs.

---

That's the tour. Back to the [docs index](README.md).
