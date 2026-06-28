# AI Page Builder for Laravel + Filament

[![Latest Version on Packagist](https://img.shields.io/packagist/v/andrecorugda/ai-page-builder.svg?style=flat-square)](https://packagist.org/packages/andrecorugda/ai-page-builder)
[![Tests](https://img.shields.io/github/actions/workflow/status/andrecorugda/ai-page-builder/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/andrecorugda/ai-page-builder/actions)
[![License](https://img.shields.io/packagist/l/andrecorugda/ai-page-builder.svg?style=flat-square)](LICENSE)

**A self-hostable, AI-driven [GrapesJS](https://grapesjs.com) landing-page builder for Filament.** Generate a whole page from a brief, drop in AI-written sections, and rewrite copy in place — then edit everything visually. Pages are stored, versioned by GrapesJS project data, published to a cached front-end route, and your prompts/data/key never leave your app.

Existing GrapesJS Filament plugins are manual drag-and-drop; this one makes AI a first-class author while keeping the output **fully editable** (AI emits a fixed, recognised section vocabulary that imports as real GrapesJS components — not an opaque blob).

> **AI is optional and provider-agnostic.** Bring any OpenRouter key. Install the companion [AI OpenRouter Gateway](https://github.com/andrecorugda/ai-openrouter-gateway) and the builder auto-wires through it — metered, versioned prompt, cost caps — with zero extra config.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- Filament 4 or 5 (for the admin UI)

## Installation

```bash
composer require andrecorugda/ai-page-builder
php artisan vendor:publish --tag="ai-page-builder-migrations"
php artisan migrate
```

Register the plugin on your Filament panel:

```php
use Andre\AiPageBuilder\Filament\AiPageBuilderPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(AiPageBuilderPlugin::make());
}
```

That's it — a **Pages** resource appears under the *Content* group. Create a page, drag the section blocks, save, and publish.

## How it works

- **`Page` model** stores the canonical GrapesJS `project_data` plus a compiled `html`/`css` snapshot and SEO `meta`.
- **`GrapesJsField`** boots a GrapesJS editor (loaded via a Filament render hook), registers the six-section vocabulary, and keeps Livewire state in sync.
- **Front-end render** serves published pages at `/{prefix}/{slug}` (cached). Disable it via `config('ai-page-builder.routes.render_enabled')` to render `Page->html`/`css` from your own routing.

## AI (optional)

Set a driver in `config/ai-page-builder.php`:

- `auto` (default) — use the AI OpenRouter Gateway if installed, else a direct OpenRouter key, else disabled (manual editing still works).
- `gateway` / `openrouter` — force one.

When the gateway is present, a pre-configured `page_builder` integration is auto-seeded (re-run with `php artisan ai-page-builder:seed-integration`) so you tune the prompt, model and cost caps from the gateway admin UI.

## Configuration

```bash
php artisan vendor:publish --tag="ai-page-builder-config"
```

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
