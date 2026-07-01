<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;

/**
 * Generation quality harness — a GOLDEN POS plan run through the real applier, then
 * asserted across the whole stack: collections build, every page renders, the data
 * widgets carry the wiring the front-end binds to, and the checkout flow actually
 * runs (order + line items + stock). This is the repeatable definition of "a
 * generated app is good" so the blank-dashboard / broken-checkout / white-page
 * classes of regression are caught here instead of in a screenshot.
 */
function goldenPosPlan(): array
{
    return [
        'collections' => [
            ['key' => 'categories', 'name' => 'Categories', 'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
            ]],
            ['key' => 'products', 'name' => 'Products', 'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
                ['key' => 'price', 'label' => 'Price', 'type' => 'decimal', 'options' => ['default' => 0]],
                ['key' => 'stock', 'label' => 'Stock', 'type' => 'integer', 'options' => ['default' => 0]],
                ['key' => 'category_id', 'label' => 'Category', 'type' => 'relation', 'options' => ['relation_model' => 'categories']],
            ]],
            ['key' => 'orders', 'name' => 'Orders', 'fields' => [
                ['key' => 'order_number', 'label' => 'Order #', 'type' => 'string'],
                ['key' => 'subtotal', 'label' => 'Subtotal', 'type' => 'decimal', 'options' => ['default' => 0]],
                ['key' => 'tax', 'label' => 'Tax', 'type' => 'decimal', 'options' => ['default' => 0]],
                ['key' => 'total', 'label' => 'Total', 'type' => 'decimal', 'options' => ['default' => 0]],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
            ]],
            ['key' => 'order_items', 'name' => 'Order items', 'fields' => [
                ['key' => 'order_id', 'label' => 'Order', 'type' => 'relation', 'options' => ['relation_model' => 'orders']],
                ['key' => 'product_id', 'label' => 'Product', 'type' => 'relation', 'options' => ['relation_model' => 'products']],
                ['key' => 'quantity', 'label' => 'Qty', 'type' => 'integer', 'options' => ['default' => 1]],
                ['key' => 'unit_price', 'label' => 'Unit', 'type' => 'decimal', 'options' => ['default' => 0]],
                ['key' => 'line_subtotal', 'label' => 'Line', 'type' => 'decimal', 'options' => ['default' => 0]],
            ]],
        ],
        'functions' => [
            ['slug' => 'dec-stock', 'name' => 'dec-stock', 'runtime' => 'expression',
                'body' => "db_update('products', args['product_id'], {'stock': db_find('products', args['product_id'])['stock'] - args['qty']})"],
        ],
        'pages' => [
            ['slug' => 'dashboard', 'title' => 'Dashboard', 'kind' => 'page', 'status' => 'published',
                'html' => '<section data-pb-block="hero" class="pb-hero"><h1 class="pb-hero__title">Dashboard</h1></section>'
                    .'<div data-pb-block="kpi" data-pb-collection="orders" data-pb-metric="sum" data-pb-field="total" data-pb-label="Sales"></div>'
                    .'<div data-pb-block="data_table" x-data="pbTable(\'orders\')" class="pb-table"></div>'],
            ['slug' => 'products', 'title' => 'Products', 'kind' => 'page', 'status' => 'published',
                'html' => '<section data-pb-block="hero" class="pb-hero"><h1 class="pb-hero__title">Products</h1></section>'
                    .'<form data-pb-record="products"><input name="name"><input name="price" type="number"><button type="submit">Add</button></form>'
                    .'<div data-pb-block="data_table" x-data="pbTable(\'products\')" class="pb-table"></div>'],
            ['slug' => 'checkout', 'title' => 'Checkout', 'kind' => 'page', 'status' => 'published',
                'html' => '<div data-pb-block="record_picker" data-pb-collection="products" data-pb-target="cart_items"></div>'
                    .'<button data-pb-flow="complete-sale">Complete Sale</button>'],
        ],
        'flows' => [
            ['slug' => 'complete-sale', 'name' => 'Complete Sale', 'trigger_type' => 'component', 'definition' => [
                'start' => 'trigger',
                'nodes' => [
                    'trigger' => ['type' => 'trigger', 'next' => ['guard']],
                    'guard' => ['type' => 'condition', 'config' => ['left' => 'input.cart_items', 'op' => 'not_empty'], 'next_true' => ['tx'], 'next_false' => ['empty']],
                    'empty' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'level' => 'error', 'message' => 'Cart empty']]]],
                    'tx' => ['type' => 'transaction', 'committed' => ['done'], 'rolled_back' => ['oops'], 'config' => ['body' => [
                        'start' => 'order',
                        'nodes' => [
                            'order' => ['type' => 'record', 'config' => ['model' => 'orders', 'operation' => 'create', 'output' => 'order', 'data' => ['order_number' => "'ORD-' ~ util_now('YmdHis')", 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'status' => 'completed']], 'next' => ['lines']],
                            'lines' => ['type' => 'loop', 'config' => ['over' => 'input.cart_items', 'item_var' => 'item', 'body' => [
                                'start' => 'line',
                                'nodes' => [
                                    'line' => ['type' => 'record', 'config' => ['model' => 'order_items', 'operation' => 'create', 'data' => ['order_id' => "vars['order']['id']", 'product_id' => "vars['item']['id']", 'quantity' => "vars['item']['qty']", 'unit_price' => "vars['item']['price']", 'line_subtotal' => "vars['item']['qty'] * vars['item']['price']"]], 'next' => ['dec']],
                                    'dec' => ['type' => 'function', 'config' => ['function' => 'dec-stock', 'args' => ['product_id' => "vars['item']['id']", 'qty' => "vars['item']['qty']"]]],
                                ],
                            ]]],
                        ],
                    ]]],
                    'done' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'level' => 'success', 'message' => 'Sale complete'], ['type' => 'setState', 'key' => 'cart_items', 'value' => []]]]],
                    'oops' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'level' => 'error', 'message' => 'Sale failed']]]],
                ],
            ]],
        ],
        'settings' => ['home_page' => 'checkout'],
    ];
}

beforeEach(function (): void {
    $this->summary = app(BuildPlanApplier::class)->apply(goldenPosPlan());
    $this->rq = app(RecordQuery::class);
});

it('applies the golden plan with no errors and builds every collection', function (): void {
    expect($this->summary['errors'] ?? [])->toBe([])
        ->and($this->summary['created']['collections'])->toContain('products', 'orders', 'order_items', 'categories');
    foreach (['products', 'orders', 'order_items', 'categories'] as $key) {
        expect(PbModel::where('key', $key)->exists())->toBeTrue();
    }
});

it('renders every generated page with its data-binding wiring intact', function (): void {
    // Dashboard: KPI + a data table the front-end binds to.
    $this->get('/p/dashboard')->assertOk()
        ->assertSee('data-pb-block="kpi"', false)
        ->assertSee("pbTable('orders')", false);
    // Products: a working record form (management), not just a read-only list.
    $this->get('/p/products')->assertOk()
        ->assertSee('data-pb-record="products"', false)
        ->assertSee('name="name"', false);
    // Checkout: the picker + the flow-triggering button.
    $this->get('/p/checkout')->assertOk()
        ->assertSee('data-pb-block="record_picker"', false)
        ->assertSee('data-pb-flow="complete-sale"', false);
});

it('resolves relation names in a list (expand=*) so tables show names not ids', function (): void {
    $categories = PbModel::where('key', 'categories')->first();
    $products = PbModel::where('key', 'products')->first();
    $cat = $this->rq->create($categories, ['name' => 'Drinks'])->toArray();
    $this->rq->create($products, ['name' => 'Coffee', 'price' => 3.5, 'stock' => 10, 'category_id' => $cat['id']]);

    $row = $this->rq->list($products, ['expand' => '*'])->items()[0]->toArray();

    // The id stays on `category_id`; the expanded row lands on the stripped key
    // `category` (so the integer cast doesn't clobber it) — the table shows its name.
    expect($row['category_id'])->toBe($cat['id'])
        ->and($row['category'])->toBeArray()
        ->and($row['category']['name'])->toBe('Drinks');
});

it('re-applies over a soft-deleted page/collection without a duplicate-key error (edit works)', function (): void {
    // Trash an existing page + collection (soft delete leaves the unique index occupied).
    \Andre\AiPageBuilder\Models\Page::where('slug', 'products')->first()->delete();
    PbModel::where('key', 'categories')->first()->delete();

    // Re-applying the SAME plan must update/restore them, not INSERT into the still-
    // occupied unique index (which is what made "edit an existing page" fail).
    $summary = app(BuildPlanApplier::class)->apply(goldenPosPlan());

    expect($summary['errors'])->toBe([])
        ->and(\Andre\AiPageBuilder\Models\Page::where('slug', 'products')->exists())->toBeTrue()   // restored, not trashed
        ->and(PbModel::where('key', 'categories')->exists())->toBeTrue();
});

it('runs the generated checkout end to end: order + line items + stock decrement', function (): void {
    $products = PbModel::where('key', 'products')->first();
    $p1 = $this->rq->create($products, ['name' => 'Coffee', 'price' => 3.5, 'stock' => 20])->toArray();
    $p2 = $this->rq->create($products, ['name' => 'Latte', 'price' => 4.0, 'stock' => 15])->toArray();

    $def = Flow::where('slug', 'complete-sale')->first()->definition;
    $ctx = app(FlowRunner::class)->run($def, ['cart_items' => [
        ['id' => $p1['id'], 'qty' => 2, 'price' => 3.5],
        ['id' => $p2['id'], 'qty' => 1, 'price' => 4.0],
    ]]);

    expect($ctx->error)->toBeNull();
    $orders = PbModel::where('key', 'orders')->first();
    $items = PbModel::where('key', 'order_items')->first();
    expect($this->rq->list($orders, [])->total())->toBe(1)
        ->and($this->rq->list($items, [])->total())->toBe(2)
        ->and((int) $this->rq->find($products, $p1['id'])->toArray()['stock'])->toBe(18)
        ->and((int) $this->rq->find($products, $p2['id'])->toArray()['stock'])->toBe(14);
});
