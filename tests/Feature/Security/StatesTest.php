<?php

declare(strict_types=1);

/*
 * Regression tests for State visibility in 2.0 (GHSA-hj3q-8gc4-6mpw): only
 * client-visible States reach rendered pages, and a page's live value can stand
 * in for a State during a flow run only when that State is client-visible.
 */

use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Variable;
use Andre\AiPageBuilder\Services\Data\VariableStore;

function quoteFlow(string $message): void
{
    Flow::create([
        'slug' => 'quote', 'name' => 'Quote', 'trigger_type' => 'component', 'is_active' => true, 'is_public' => true,
        'definition' => ['start' => 't', 'nodes' => [
            't' => ['type' => 'trigger', 'next' => ['r']],
            'r' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => $message]]]],
        ]],
    ]);
}

it('does not put a server-only State in public page HTML', function (): void {
    Variable::create(['key' => 'api_key', 'type' => 'string', 'value' => 'sk-live-SUPERSECRET']);
    Page::factory()->create(['slug' => 'about', 'status' => 'published', 'html' => '<p>About us</p>']);

    $this->get('/p/about')->assertOk()->assertDontSee('sk-live-SUPERSECRET', false);
});

it('seeds the page store with client-visible States only', function (): void {
    Variable::create(['key' => 'store_name', 'type' => 'string', 'value' => 'Corner Shop', 'is_public' => true]);
    Variable::create(['key' => 'tax_rate', 'type' => 'number', 'value' => '0.2']);
    Page::factory()->create(['slug' => 'shop', 'status' => 'published', 'html' => '<span x-text="$store.app.store_name"></span>']);

    $html = $this->get('/p/shop')->assertOk()->getContent();

    expect($html)->toContain('Corner Shop')->not->toContain('tax_rate');
});

it('stops serving a State from cached pages the moment it is made server-only', function (): void {
    $state = Variable::create(['key' => 'promo', 'type' => 'string', 'value' => 'PROMO-CODE-123', 'is_public' => true]);
    Page::factory()->create(['slug' => 'home2', 'status' => 'published']);
    $this->get('/p/home2')->assertSee('PROMO-CODE-123', false); // cached with the State

    $state->update(['is_public' => false]);
    app(VariableStore::class)->set('promo', 'PROMO-CODE-123'); // flush the request memo

    $this->get('/p/home2')->assertDontSee('PROMO-CODE-123', false);
});

it('ignores a visitor value for a server-only State in a flow run', function (): void {
    Variable::create(['key' => 'tax_rate', 'type' => 'number', 'value' => '0.2']);
    quoteFlow('rate={{ states.tax_rate }}');

    $res = $this->postJson('/pb-flow/quote', ['input' => ['tax_rate' => 0]])->assertOk();

    expect($res->json('actions.0.message'))->toBe('rate=0.2');
});

it('still uses the page value for a client-visible State and for page-local keys', function (): void {
    Variable::create(['key' => 'coupon', 'type' => 'string', 'value' => 'none', 'is_public' => true]);
    quoteFlow('{{ states.coupon }}/{{ states.cart_count }}');

    $res = $this->postJson('/pb-flow/quote', ['input' => ['coupon' => 'SPRING', 'cart_count' => 3]])->assertOk();

    expect($res->json('actions.0.message'))->toBe('SPRING/3');
});

it('resolves state() in an expression the same way as {{ states.x }}', function (): void {
    Variable::create(['key' => 'tax_rate', 'type' => 'number', 'value' => '0.2']);
    Variable::create(['key' => 'coupon', 'type' => 'string', 'value' => 'none', 'is_public' => true]);
    FlowFunction::create(['slug' => 'read-states', 'name' => 'read-states', 'runtime' => 'expression', 'body' => "state('tax_rate') ~ '|' ~ state('coupon') ~ '|' ~ states['coupon']"]);

    $overlay = app(VariableStore::class)->clientOverlay(['tax_rate' => 0, 'coupon' => 'SPRING']);
    $ctx = app(FlowRunner::class)->run(['start' => 'f', 'nodes' => [
        'f' => ['type' => 'function', 'config' => ['function' => 'read-states', 'output' => 'out'], 'next' => ['r']],
        'r' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => '{{ states.tax_rate }}|{{ states.coupon }}']]]],
    ]], [], $overlay);

    expect($ctx->get('vars.out'))->toBe('0.2|SPRING|SPRING')
        ->and($ctx->actions[0]['message'])->toBe('0.2|SPRING');
});

it('lists States and marks the ones pages reference as client-visible', function (): void {
    Variable::create(['key' => 'store_name', 'type' => 'string', 'value' => 'Shop']);
    Variable::create(['key' => 'api_key', 'type' => 'string', 'value' => 'sk']);
    Page::factory()->create(['slug' => 'shop', 'html' => '<b x-text="$store.app.store_name"></b>']);

    $this->artisan('ai-page-builder:states', ['--referenced' => true])->assertSuccessful();

    expect(Variable::query()->where('key', 'store_name')->value('is_public'))->toBeTruthy()
        ->and(Variable::query()->where('key', 'api_key')->value('is_public'))->toBeFalsy();

    $this->artisan('ai-page-builder:states', ['--private' => 'store_name', '--public' => 'api_key'])->assertSuccessful();
    expect(Variable::query()->where('key', 'store_name')->value('is_public'))->toBeFalsy();
});
