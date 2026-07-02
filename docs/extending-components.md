# Extending: custom & premium components

[← Docs index](README.md) · see also [Extending](extending.md), [Flows](extending-flows.md), [Functions](extending-functions.md)

The editor's block palette is open. A host app or third-party package can add its own **components** — draggable GrapesJS blocks — without forking Synapse. Anything you register shows up automatically in:

- the builder UI — the block appears in the GrapesJS block manager, grouped by its category;
- the block vocabulary — `BlockVocabulary::all()` / `find()` / `toArray()` include it;
- the **capability catalogue** — `PageBuilder::capabilities()` lists it as `kind: 'component'`.

No core change is required. This is also the seam an **open-core premium component pack** registers through (see [Premium component packs](#premium-component-packs)).

## The registration seam

One facade method, meant to be called from a service provider's `boot()`:

```php
use Andre\AiPageBuilder\Facades\PageBuilder;

PageBuilder::registerComponent($block);   // a SectionBlock instance
```

It resolves to the [`ComponentRegistry`](../src/Capabilities/ComponentRegistry.php) singleton, which is seeded with the built-in blocks and holds any you add. Register at boot time (before the editor field or block manager renders). Re-registering an existing `key` overrides that block in place, keeping its original position in the ordering — so a pack can override a built-in.

The public `BlockVocabulary` accessors (`all()`, `keys()`, `find()`, `toArray()`) all read *through* this registry, so a registered component is visible everywhere a consumer reads the vocabulary.

## A block is a `SectionBlock`

A component is a [`SectionBlock`](../src/Blocks/SectionBlock.php) value object — the same type the built-ins use:

```php
new SectionBlock(
    key:         string,  // stable id; also the data-pb-block="{key}" attribute
    label:       string,  // shown in the block manager
    category:    string,  // block-manager group; 'Sections' opts into the AI vocabulary (see below)
    template:    string,  // the block's HTML
    description: string,  // optional; surfaced to the AI / capabilities
    icon:        string,  // optional; block-manager icon key
    settings:    array,   // optional; ComponentSetting[] — author-configurable traits (see below)
);
```

Keep the `data-pb-block="{key}"` convention on the wrapping element of any block you want imported as a **labelled, editable component** and recognised by the validator.

### Author-configurable settings

A block can declare its own **settings** — the component analog of a flow node's
config inputs. Each becomes a trait in the editor's "Component settings" panel on
the selected component, and writes a **plain attribute** named by its `key` (kept
by the sanitizer), which your `template` / `custom_js` reads at render time:

```php
use Andre\AiPageBuilder\Blocks\ComponentSetting;

new SectionBlock(
    key: 'countdown',
    label: 'Countdown',
    category: 'Pro',
    template: '<div data-pb-block="countdown" data-target="" data-style="plain">…</div>',
    settings: [
        ComponentSetting::make('data-target', 'Target date'),                       // text (default)
        new ComponentSetting('data-style', 'Style', 'select',                        // select
            options: ['flip' => 'Flip', 'plain' => 'Plain'], category: 'Appearance'),
    ],
);
```

`type` is one of `text` | `number` | `checkbox` | `select` (select uses `options`
as a `value => label` map); `category` groups the trait (default `Settings`). Name
each setting after the attribute your template consumes (e.g. `data-*`). Primitive markup without it still drops in as free-form GrapesJS content, but won't be a named component. Use inline styles + stable `pb-{key}__*` classes (the published page carries no host Tailwind), the same convention the built-ins follow — see [`BlockVocabulary`](../src/Blocks/BlockVocabulary.php) for reference blocks.

## Register a component from a service provider

```php
namespace App\Providers;

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Blocks\SectionBlock;
use Andre\AiPageBuilder\Facades\PageBuilder;
use Illuminate\Support\ServiceProvider;

class ComponentExtensionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PageBuilder::registerComponent(new SectionBlock(
            key: 'price_table',
            label: 'Price table',
            category: BlockVocabulary::SECTION_CATEGORY,   // 'Sections' → joins the AI page vocabulary
            template: <<<'HTML'
            <section data-pb-block="price_table" class="pb-price-table" style="padding:4rem 1.5rem;">
              <h2 class="pb-price-table__title" style="text-align:center;font-size:2rem;margin:0 0 2.5rem;">Plans</h2>
              <div class="pb-price-table__grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;max-width:60rem;margin:0 auto;">
                <div class="pb-price-table__plan" style="padding:2rem;border:1px solid #e2e8f0;border-radius:0.75rem;text-align:center;">
                  <h3 style="margin:0 0 0.5rem;">Basic</h3>
                  <p style="font-size:2rem;font-weight:700;margin:0;">$19<span style="font-size:1rem;color:#64748b;">/mo</span></p>
                </div>
                <div class="pb-price-table__plan" style="padding:2rem;border:2px solid #4f46e5;border-radius:0.75rem;text-align:center;">
                  <h3 style="margin:0 0 0.5rem;">Team</h3>
                  <p style="font-size:2rem;font-weight:700;margin:0;">$49<span style="font-size:1rem;color:#64748b;">/mo</span></p>
                </div>
                <div class="pb-price-table__plan" style="padding:2rem;border:1px solid #e2e8f0;border-radius:0.75rem;text-align:center;">
                  <h3 style="margin:0 0 0.5rem;">Scale</h3>
                  <p style="font-size:2rem;font-weight:700;margin:0;">$149<span style="font-size:1rem;color:#64748b;">/mo</span></p>
                </div>
              </div>
            </section>
            HTML,
            description: 'Three pricing plans in a responsive grid.',
            icon: 'table',
        ));
    }
}
```

If the block's template is expensive to build, resolve it from a factory of your own — `registerComponent()` just needs the finished `SectionBlock`.

## It shows up everywhere, automatically

Once registered, a component needs no further wiring:

- **Builder UI** — the block appears in the GrapesJS block manager, grouped by its `category`. `PageBuilder::components()` (and `BlockVocabulary::toArray()`) return the serialized list the JS block manager consumes.
- **Vocabulary** — `BlockVocabulary::all()`, `find('price_table')`, and `toArray()` all include it, because they delegate to the registry.
- **Capability catalogue** — it appears in `PageBuilder::capabilities()` as an entry with `kind: 'component'` (shape: `{key, label, kind, category, description, icon}`), alongside the flow nodes and helpers. That single list is what an MCP server / AI tool layer reads.

## The `Sections`-category nuance (AI page vocabulary)

There are two levels of "shows up":

- **Block manager + capabilities** — *every* registered component appears here, regardless of category. It's a drag-only block your users can place by hand.
- **AI page-generation vocabulary** — the AI's constrained section list is `BlockVocabulary::keys()`, which is scoped to the **`Sections`** category (`BlockVocabulary::SECTION_CATEGORY`). A registered component joins the AI's page-building vocabulary **only if it declares that category**.

So the category is the switch:

```php
// AI can compose whole pages with this section:
category: BlockVocabulary::SECTION_CATEGORY,   // 'Sections'

// Drag-only: available in the palette + capabilities, but the AI won't emit it
// as a top-level page section:
category: 'Components',   // or 'Forms', 'Data', 'Basic', 'Shapes', or any custom group
```

This mirrors the built-ins: the top-level page sections (`hero`, `features`, `pricing`, …) are `Sections`, while the finer-grained blocks (`card`, `modal`, form inputs, data tables) live in other categories and stay drag-only. Give your block the `Sections` category when it's a full-width, self-contained page section the AI should be able to lay out; give it another category when it's a smaller piece meant for manual composition.

## Premium component packs

Because registration is a public seam with **no core change**, a **premium component pack** can ship as a separate, commercially-licensed package that:

1. `require`s the MIT core (`andre/ai-page-builder`);
2. registers its blocks from its own service provider's `boot()` with `PageBuilder::registerComponent(...)`;
3. is distributed under its own license and (typically) a private Composer repository / license gate.

The core stays MIT and untouched; the pack's blocks become first-class — in the palette, the vocabulary, and the capability catalogue — the moment its provider boots. This is the open-core model the `ComponentRegistry` was built for, the same story as [`NodeRegistry`](extending-flows.md) for nodes and [`HelperRegistry`](extending-functions.md) for helpers.

A pack's provider is ordinary Laravel:

```php
namespace Acme\SynapsePremium;

use Andre\AiPageBuilder\Facades\PageBuilder;
use Illuminate\Support\ServiceProvider;

class PremiumComponentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ($this->blocks() as $block) {
            PageBuilder::registerComponent($block);
        }
    }

    /** @return iterable<\Andre\AiPageBuilder\Blocks\SectionBlock> */
    private function blocks(): iterable
    {
        // your premium SectionBlock definitions, optionally behind a license check
        // …
    }
}
```

---

Back to the [docs index](README.md).
