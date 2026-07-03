<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Watcher;
use Andre\AiPageBuilder\Services\AppExporter;
use Andre\AiPageBuilder\Services\AppImporter;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Support\Facades\Schema;

/**
 * Build a representative app (one collection with fields + a state + a function
 * + a flow + a page + the home_page setting) through the applier — the same
 * path the AI / import use — so the export has real rows to serialise.
 */
function seedSampleApp(): void
{
    app(BuildPlanApplier::class)->apply([
        'collections' => [[
            'key' => 'leads',
            'name' => 'Leads',
            'has_timestamps' => true,
            'has_soft_deletes' => false,
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
                ['key' => 'email', 'label' => 'Email', 'type' => 'string', 'options' => ['unique' => true]],
            ],
        ]],
        'states' => [
            ['key' => 'cart_total', 'type' => 'number', 'value' => 42],
        ],
        'functions' => [
            ['slug' => 'markup', 'name' => 'Markup', 'runtime' => 'expression', 'body' => 'args["price"] * 1.2'],
        ],
        'flows' => [[
            'slug' => 'on-lead',
            'name' => 'On Lead',
            'trigger_type' => 'collection',
            'trigger_config' => ['collection' => 'leads', 'events' => ['created']],
            'definition' => ['start' => 'n1', 'nodes' => ['n1' => ['type' => 'trigger', 'config' => [], 'next' => []]]],
        ]],
        'partials' => [[
            'slug' => 'nav',
            'name' => 'Top nav',
            'html' => '<header class="pb-nav"><span>Brand</span></header>',
            'custom_css' => '.pb-nav{display:flex}',
        ]],
        'pages' => [[
            'slug' => 'home',
            'title' => 'Home',
            'kind' => 'page',
            'status' => 'published',
            'html' => '<div data-pb-partial="nav"></div><section data-pb-block="hero"><h1>Hi</h1></section>',
        ]],
        'settings' => ['home_page' => 'home'],
    ]);
}

it('exports the app as an import-ready, plan-shaped document', function (): void {
    seedSampleApp();

    $plan = app(AppExporter::class)->export();

    // Top-level shape: version + the sections the applier reads.
    expect($plan)->toHaveKeys(['version', 'collections', 'states', 'functions', 'flows', 'watchers', 'partials', 'pages', 'settings'])
        ->and($plan['version'])->toBe(AppExporter::VERSION);

    // Partials — the shared chrome pages embed — travel with the app.
    expect($plan['partials'])->toHaveCount(1)
        ->and($plan['partials'][0])->toMatchArray([
            'slug' => 'nav',
            'name' => 'Top nav',
            'html' => '<header class="pb-nav"><span>Brand</span></header>',
            'custom_css' => '.pb-nav{display:flex}',
        ]);

    // Collection with fields (+ options) carried through.
    expect($plan['collections'])->toHaveCount(1);
    $collection = $plan['collections'][0];
    expect($collection['key'])->toBe('leads')
        ->and($collection['name'])->toBe('Leads')
        ->and($collection['has_timestamps'])->toBeTrue()
        ->and($collection['has_soft_deletes'])->toBeFalse()
        ->and($collection['fields'])->toHaveCount(2)
        ->and($collection['fields'][0]['key'])->toBe('name')
        ->and($collection['fields'][0]['type'])->toBe('string')
        ->and($collection['fields'][0]['options'])->toBe(['required' => true]);

    // State value round-trips as its native type.
    expect($plan['states'][0])->toMatchArray(['key' => 'cart_total', 'type' => 'number', 'value' => 42]);

    // Function, flow, page, settings.
    expect($plan['functions'][0])->toMatchArray(['slug' => 'markup', 'runtime' => 'expression'])
        ->and($plan['flows'][0]['slug'])->toBe('on-lead')
        ->and($plan['flows'][0]['definition'])->toHaveKey('nodes')
        ->and($plan['pages'][0])->toMatchArray(['slug' => 'home', 'kind' => 'page', 'status' => 'published'])
        ->and($plan['settings']['home_page'])->toBe('home');

    // The watcher materialized from the collection flow travels with the app.
    expect($plan['watchers'])->toHaveCount(1)
        ->and($plan['watchers'][0])->toMatchArray([
            'source_type' => 'collection',
            'source_key' => 'leads',
            'event' => 'created',
            'target_type' => 'flow',
            'target_key' => 'on-lead',
            'is_active' => true,
        ]);
});

it('round-trips losslessly: export then import recreates every row', function (): void {
    seedSampleApp();
    $plan = app(AppExporter::class)->export();

    // Tear the app down so the import has to recreate it from the export alone.
    Schema::dropIfExists('pb_leads');
    PbField::query()->delete();
    PbModel::query()->forceDelete();
    Flow::query()->forceDelete();
    Watcher::query()->forceDelete();
    FlowFunction::query()->forceDelete();
    Page::query()->forceDelete();
    Partial::query()->forceDelete();
    app(VariableStore::class)->forget('cart_total');
    app(Settings::class)->forget('home_page');

    $summary = app(AppImporter::class)->import($plan);

    expect($summary['errors'])->toBe([])
        ->and($summary['created']['collections'])->toBe(['leads'])
        ->and($summary['created']['states'])->toBe(['cart_total'])
        ->and($summary['created']['functions'])->toBe(['markup'])
        ->and($summary['created']['flows'])->toBe(['on-lead'])
        ->and($summary['created']['watchers'])->toBe(['collection:leads created → on-lead'])
        ->and($summary['created']['partials'])->toBe(['nav'])
        ->and($summary['created']['pages'])->toBe(['home']);

    // Rows + physical table are back.
    expect(PbModel::query()->where('key', 'leads')->exists())->toBeTrue()
        ->and(Schema::hasTable('pb_leads'))->toBeTrue()
        ->and(Schema::hasColumns('pb_leads', ['name', 'email']))->toBeTrue()
        ->and(FlowFunction::query()->where('slug', 'markup')->exists())->toBeTrue()
        ->and(Flow::query()->where('slug', 'on-lead')->exists())->toBeTrue()
        ->and(Watcher::query()->where('target_key', 'on-lead')->where('event', 'created')->exists())->toBeTrue()
        ->and(Page::query()->where('slug', 'home')->firstOrFail()->status->value)->toBe('published')
        ->and(Partial::query()->where('slug', 'nav')->exists())->toBeTrue()
        ->and(app(VariableStore::class)->get('cart_total'))->toBe(42)
        ->and(app(Settings::class)->get('home_page'))->toBe('home');
});

it('export of an empty app is still a valid, importable plan', function (): void {
    $plan = app(AppExporter::class)->export();

    expect($plan['collections'])->toBe([])
        ->and($plan['pages'])->toBe([])
        ->and($plan['flows'])->toBe([])
        ->and($plan['watchers'])->toBe([]);

    // An empty plan imports cleanly (creates nothing, no errors).
    $summary = app(AppImporter::class)->import($plan);
    expect($summary['errors'])->toBe([])
        ->and($summary['created']['collections'])->toBe([]);
});
