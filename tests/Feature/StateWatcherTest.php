<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowRun;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Watcher;
use Andre\AiPageBuilder\Services\Data\VariableStore;

/**
 * A minimal notify flow, observable via its FlowRun row.
 */
function makeStateFlow(string $slug): Flow
{
    return Flow::create([
        'slug' => $slug,
        'name' => $slug,
        'trigger_type' => 'manual',
        'is_active' => true,
        'definition' => [
            'start' => 'n1',
            'nodes' => [
                'n1' => ['type' => 'trigger', 'next' => ['n2']],
                'n2' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'fired']]]],
            ],
        ],
    ]);
}

/**
 * @param  array<string,mixed>  $config
 */
function makeStateWatcher(string $stateKey, string $targetSlug, array $config = []): Watcher
{
    return Watcher::create([
        'name' => "state $stateKey → $targetSlug",
        'source_type' => 'state',
        'source_key' => $stateKey,
        'event' => 'changed',
        'config' => $config === [] ? null : $config,
        'target_type' => 'flow',
        'target_key' => $targetSlug,
        'is_active' => true,
    ]);
}

it('fires a state watcher when a global changes via VariableStore::set', function (): void {
    makeStateFlow('on-msg');
    makeStateWatcher('msg', 'on-msg');
    $store = app(VariableStore::class);

    $store->set('msg', 'hello');
    $run = FlowRun::where('flow_slug_snapshot', 'on-msg')->first();
    expect($run)->not->toBeNull()
        ->and($run->input['key'])->toBe('msg')
        ->and($run->input['old'])->toBeNull()
        ->and($run->input['new'])->toBe('hello');

    // Re-setting the same value must NOT fire again.
    $store->set('msg', 'hello');
    expect(FlowRun::where('flow_slug_snapshot', 'on-msg')->count())->toBe(1);

    // A real change fires again.
    $store->set('msg', 'bye');
    expect(FlowRun::where('flow_slug_snapshot', 'on-msg')->count())->toBe(2);
});

it('only fires on the configured from → to transition', function (): void {
    makeStateFlow('on-won');
    makeStateWatcher('status', 'on-won', ['from' => 'open', 'to' => 'won']);
    $store = app(VariableStore::class);

    // First write establishes 'open' (old was null) — transition doesn't match.
    $store->set('status', 'open');
    expect(FlowRun::where('flow_slug_snapshot', 'on-won')->count())->toBe(0);

    // open → won matches.
    $store->set('status', 'won');
    expect(FlowRun::where('flow_slug_snapshot', 'on-won')->count())->toBe(1);

    // won → lost does not.
    $store->set('status', 'lost');
    expect(FlowRun::where('flow_slug_snapshot', 'on-won')->count())->toBe(1);
});

it('applies an operator condition to the new value', function (): void {
    makeStateFlow('on-high');
    makeStateWatcher('score', 'on-high', ['op' => 'gte', 'value' => 50]);
    $store = app(VariableStore::class);

    $store->set('score', 10);
    expect(FlowRun::where('flow_slug_snapshot', 'on-high')->count())->toBe(0);

    $store->set('score', 80);
    expect(FlowRun::where('flow_slug_snapshot', 'on-high')->count())->toBe(1);
});

it('watches a sub-path of an Object state', function (): void {
    makeStateFlow('on-city');
    makeStateWatcher('addr', 'on-city', ['path' => 'address.city']);
    $store = app(VariableStore::class);

    $store->set('addr', ['address' => ['city' => 'NYC', 'zip' => '10001']]);
    expect(FlowRun::where('flow_slug_snapshot', 'on-city')->count())->toBe(1);

    // Changing a different sub-key leaves the watched path unchanged -> no fire.
    $store->set('addr', ['address' => ['city' => 'NYC', 'zip' => '99999']]);
    expect(FlowRun::where('flow_slug_snapshot', 'on-city')->count())->toBe(1);

    // Changing the watched path fires.
    $store->set('addr', ['address' => ['city' => 'LA', 'zip' => '99999']]);
    expect(FlowRun::where('flow_slug_snapshot', 'on-city')->count())->toBe(2);
});

it('does not fire when there is no matching state watcher', function (): void {
    makeStateFlow('on-msg');
    makeStateWatcher('other', 'on-msg');

    app(VariableStore::class)->set('msg', 'hello');

    expect(FlowRun::where('flow_slug_snapshot', 'on-msg')->count())->toBe(0);
});

it('server dispatch skips browser-side watchers (they fire from the page)', function (): void {
    makeStateFlow('on-msg');
    makeStateWatcher('msg', 'on-msg', ['side' => 'client']);

    app(VariableStore::class)->set('msg', 'hello');

    expect(FlowRun::where('flow_slug_snapshot', 'on-msg')->count())->toBe(0);
});

it('renders active browser-side watchers into the page runtime (and only those)', function (): void {
    makeStateFlow('on-client');
    makeStateFlow('on-server');
    makeStateWatcher('cart_items', 'on-client', ['side' => 'client', 'path' => 'customer.city']);
    makeStateWatcher('cart_items', 'on-server'); // server-side — must NOT be injected

    Page::create([
        'slug' => 'watch-me',
        'title' => 'Watch me',
        'status' => 'published',
        'html' => '<section><h1>hello</h1></section>',
    ]);

    $html = $this->get('/p/watch-me')->assertOk()->getContent();

    expect($html)->toContain('on-client')
        ->and($html)->toContain('customer.city')
        ->and($html)->not->toContain('on-server');
});
