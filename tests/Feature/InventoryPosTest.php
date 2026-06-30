<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;

/**
 * Phase 6 — end-to-end proof: a real inventory + POS checkout assembled ENTIRELY
 * from the new building blocks (Transaction + Loop nodes, Record node, and the
 * db.* / util.* helpers) with NO php/eval. Demonstrates the happy path AND atomic
 * rollback when a business rule (enough stock) fails mid-checkout.
 */
function makePosCollection(string $key, array $fields): PbModel
{
    $model = PbModel::create([
        'key' => $key,
        'table_name' => PbModel::physicalTableName($key),
        'name' => ucfirst($key),
        'has_timestamps' => true,
        'has_soft_deletes' => false,
    ]);
    foreach ($fields as $i => $f) {
        $model->fields()->create($f + ['sort' => $i]);
    }
    app(SchemaSynchronizer::class)->sync($model->fresh());

    return $model->fresh();
}

function definePosFunction(string $slug, string $body): void
{
    FlowFunction::create(['slug' => $slug, 'name' => $slug, 'runtime' => 'expression', 'body' => $body]);
}

/** The checkout flow definition — what an author would build on the canvas. */
function checkoutFlow(): array
{
    return [
        'start' => 'checkout',
        'nodes' => [
            'checkout' => [
                'type' => 'transaction',
                'committed' => 'ok',
                'rolled_back' => 'fail',
                'config' => [
                    'body' => [
                        'start' => 'mk_order',
                        'nodes' => [
                            'mk_order' => [
                                'type' => 'record',
                                'config' => [
                                    'operation' => 'create',
                                    'model' => 'orders',
                                    'data' => ['customer_name' => '{{ input.customer_name }}', 'total' => 0, 'status' => 'open'],
                                    'output' => 'order',
                                ],
                                'next' => ['lines'],
                            ],
                            'lines' => [
                                'type' => 'loop',
                                'config' => [
                                    'over' => 'input.cart',
                                    'item_var' => 'item',
                                    'body' => [
                                        'start' => 'assert',
                                        'nodes' => [
                                            'assert' => ['type' => 'function', 'config' => ['function' => 'assert-stock'], 'next' => ['dec']],
                                            'dec' => ['type' => 'function', 'config' => ['function' => 'decrement-stock'], 'next' => ['line']],
                                            'line' => ['type' => 'function', 'config' => ['function' => 'add-line']],
                                        ],
                                    ],
                                ],
                                'next' => ['total'],
                            ],
                            'total' => ['type' => 'function', 'config' => ['function' => 'order-total']],
                        ],
                    ],
                ],
            ],
            'ok' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'Order placed!', 'level' => 'success']]]],
            'fail' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'Checkout failed: out of stock.', 'level' => 'error']]]],
        ],
    ];
}

beforeEach(function (): void {
    $this->products = makePosCollection('products', [
        ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
        ['key' => 'sku', 'label' => 'SKU', 'type' => 'string', 'options' => ['unique' => true]],
        ['key' => 'price', 'label' => 'Price', 'type' => 'decimal'],
        ['key' => 'stock', 'label' => 'Stock', 'type' => 'integer'],
    ]);
    $this->orders = makePosCollection('orders', [
        ['key' => 'customer_name', 'label' => 'Customer', 'type' => 'string'],
        ['key' => 'total', 'label' => 'Total', 'type' => 'decimal'],
        ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
    ]);
    $this->orderItems = makePosCollection('order_items', [
        ['key' => 'order_id', 'label' => 'Order', 'type' => 'integer'],
        ['key' => 'product_id', 'label' => 'Product', 'type' => 'integer'],
        ['key' => 'qty', 'label' => 'Qty', 'type' => 'integer'],
        ['key' => 'subtotal', 'label' => 'Subtotal', 'type' => 'decimal'],
    ]);

    // The reusable checkout functions (expression runtime, calling helpers).
    definePosFunction('assert-stock', "util_assert(db_find('products', vars['item']['product_id'])['stock'] >= vars['item']['qty'], 'Insufficient stock')");
    definePosFunction('decrement-stock', "db_update('products', vars['item']['product_id'], {'stock': db_find('products', vars['item']['product_id'])['stock'] - vars['item']['qty']})");
    definePosFunction('add-line', "db_create('order_items', {'order_id': vars['order']['id'], 'product_id': vars['item']['product_id'], 'qty': vars['item']['qty'], 'subtotal': db_find('products', vars['item']['product_id'])['price'] * vars['item']['qty']})");
    definePosFunction('order-total', "db_update('orders', vars['order']['id'], {'total': db_aggregate('order_items', {'metric': 'sum', 'field': 'subtotal', 'filter': {'order_id': {'eq': vars['order']['id']}}})['total']})");

    $rq = app(RecordQuery::class);
    $this->p1 = $rq->create($this->products, ['name' => 'Widget', 'sku' => 'P1', 'price' => 10, 'stock' => 5])->toArray();
    $this->p2 = $rq->create($this->products, ['name' => 'Gadget', 'sku' => 'P2', 'price' => 4, 'stock' => 10])->toArray();

    $this->runner = app(FlowRunner::class);
    $this->rq = $rq;
});

it('checks out a cart: creates the order, decrements stock, totals the lines', function (): void {
    $ctx = $this->runner->run(checkoutFlow(), [
        'customer_name' => 'Ada',
        'cart' => [
            ['product_id' => $this->p1['id'], 'qty' => 2],
            ['product_id' => $this->p2['id'], 'qty' => 3],
        ],
    ]);

    $orders = $this->rq->list($this->orders, [])->items();
    $order = (array) $orders[0]->toArray();

    expect($orders)->toHaveCount(1)
        ->and((float) $order['total'])->toBe(32.0)            // 2*10 + 3*4
        ->and($this->rq->list($this->orderItems, [])->total())->toBe(2)
        ->and((int) $this->rq->find($this->products, $this->p1['id'])->toArray()['stock'])->toBe(3)
        ->and((int) $this->rq->find($this->products, $this->p2['id'])->toArray()['stock'])->toBe(7)
        ->and(collect($ctx->actions)->pluck('message'))->toContain('Order placed!')
        ->and(collect($ctx->steps)->pluck('node'))->toContain('ok');
});

it('rolls the whole checkout back when one line lacks stock', function (): void {
    $ctx = $this->runner->run(checkoutFlow(), [
        'customer_name' => 'Bob',
        'cart' => [
            ['product_id' => $this->p2['id'], 'qty' => 1],   // fine
            ['product_id' => $this->p1['id'], 'qty' => 99],  // exceeds stock 5 → assert fails
        ],
    ]);

    expect($this->rq->list($this->orders, [])->total())->toBe(0)             // order rolled back
        ->and($this->rq->list($this->orderItems, [])->total())->toBe(0)      // lines rolled back
        ->and((int) $this->rq->find($this->products, $this->p1['id'])->toArray()['stock'])->toBe(5)  // stock intact
        ->and((int) $this->rq->find($this->products, $this->p2['id'])->toArray()['stock'])->toBe(10) // the good line's decrement rolled back too
        ->and(collect($ctx->steps)->pluck('node'))->toContain('fail')
        ->and(collect($ctx->actions)->pluck('message'))->not->toContain('Order placed!');
});
