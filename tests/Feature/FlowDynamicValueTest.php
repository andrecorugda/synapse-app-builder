<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;

/**
 * Guards the "last mile" of AI-generated checkout flows: the record-node `data`
 * / `id` and function-node `args` must resolve BARE Symfony-EL expressions —
 * `vars.order['id']`, `'ORD-' ~ util_now(...)` — not just `{{ }}` tokens. This
 * is exactly the shape the app-builder AI emits, so the engine has to run it as
 * written rather than passing the expression text through as a literal string.
 */
function makeDynCollection(string $key, array $fields): PbModel
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

describe('FlowContext::resolveDynamic', function (): void {
    it('passes plain literals through untouched', function (): void {
        $ctx = new FlowContext(['name' => 'Ada']);
        expect($ctx->resolveDynamic('completed'))->toBe('completed')
            ->and($ctx->resolveDynamic('Order placed!'))->toBe('Order placed!')
            ->and($ctx->resolveDynamic(0))->toBe(0)
            ->and($ctx->resolveDynamic('inputs'))->toBe('inputs'); // "input" prefix but not a path
    });

    it('interpolates {{ }} tokens as before', function (): void {
        $ctx = new FlowContext(['name' => 'Ada']);
        expect($ctx->resolveDynamic('Hi {{ input.name }}'))->toBe('Hi Ada');
    });

    it('evaluates a bare EL expression with its type preserved', function (): void {
        $ctx = new FlowContext(['qty' => 3, 'price' => 4]);
        $ctx->set('order', ['id' => 42]);
        expect($ctx->resolveDynamic("vars.order['id']"))->toBe(42)
            ->and($ctx->resolveDynamic("input['qty'] * input['price']"))->toBe(12);
    });

    it('falls back to the raw string when an expression fails to evaluate', function (): void {
        $ctx = new FlowContext([]);
        // Looks like an expression (leading quote) but references a missing helper.
        expect($ctx->resolveDynamic("'x' ~ nope_missing()"))->toBe("'x' ~ nope_missing()");
    });
});

describe('AI-style checkout flow (args + bare-EL record data)', function (): void {
    beforeEach(function (): void {
        $this->products = makeDynCollection('products', [
            ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['key' => 'price', 'label' => 'Price', 'type' => 'decimal'],
            ['key' => 'stock', 'label' => 'Stock', 'type' => 'integer'],
        ]);
        $this->orders = makeDynCollection('orders', [
            ['key' => 'order_number', 'label' => 'Order #', 'type' => 'string'],
            ['key' => 'total', 'label' => 'Total', 'type' => 'decimal', 'options' => ['default' => 0]],
            ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
        ]);
        $this->orderItems = makeDynCollection('order_items', [
            ['key' => 'order_id', 'label' => 'Order', 'type' => 'integer'],
            ['key' => 'product_id', 'label' => 'Product', 'type' => 'integer'],
            ['key' => 'quantity', 'label' => 'Qty', 'type' => 'integer'],
            ['key' => 'unit_price', 'label' => 'Unit', 'type' => 'decimal'],
            ['key' => 'line_subtotal', 'label' => 'Line', 'type' => 'decimal'],
        ]);

        // Functions take explicit args and read them with EL bracket access — the
        // exact style the app-builder AI generates.
        FlowFunction::create(['slug' => 'update-stock', 'name' => 'update-stock', 'runtime' => 'expression',
            'body' => "db_update('products', args['product_id'], {'stock': db_find('products', args['product_id'])['stock'] - args['quantity']})"]);

        $rq = app(RecordQuery::class);
        $this->p1 = $rq->create($this->products, ['name' => 'Coffee', 'price' => 3.5, 'stock' => 5])->toArray();
        $this->p2 = $rq->create($this->products, ['name' => 'Latte', 'price' => 4.0, 'stock' => 8])->toArray();
        $this->rq = $rq;
    });

    it('creates the order + line items and decrements stock from bare-EL config', function (): void {
        $flow = [
            'start' => 'tx',
            'nodes' => [
                'tx' => [
                    'type' => 'transaction',
                    'committed' => 'ok',
                    'config' => ['body' => [
                        'start' => 'mk_order',
                        'nodes' => [
                            'mk_order' => [
                                'type' => 'record',
                                'config' => [
                                    'model' => 'orders', 'operation' => 'create', 'output' => 'order',
                                    'data' => [
                                        // bare EL string-concat with helpers
                                        'order_number' => "'ORD-' ~ util_number_format(1, 0)",
                                        'total' => 0,
                                        'status' => 'completed',
                                    ],
                                ],
                                'next' => ['lines'],
                            ],
                            'lines' => [
                                'type' => 'loop',
                                'config' => [
                                    'over' => 'input.cart_items', 'item_var' => 'item',
                                    'body' => [
                                        'start' => 'mk_line',
                                        'nodes' => [
                                            'mk_line' => [
                                                'type' => 'record',
                                                'config' => [
                                                    'model' => 'order_items', 'operation' => 'create',
                                                    'data' => [
                                                        'order_id' => "vars.order['id']",
                                                        'product_id' => "vars.item['id']",
                                                        'quantity' => "vars.item['quantity']",
                                                        'unit_price' => "vars.item['price']",
                                                        'line_subtotal' => "vars.item['quantity'] * vars.item['price']",
                                                    ],
                                                ],
                                                'next' => ['dec'],
                                            ],
                                            'dec' => [
                                                'type' => 'function',
                                                'config' => ['function' => 'update-stock', 'args' => [
                                                    'product_id' => "vars.item['id']",
                                                    'quantity' => "vars.item['quantity']",
                                                ]],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]],
                ],
                'ok' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'Order placed!', 'level' => 'success']]]],
            ],
        ];

        $ctx = app(FlowRunner::class)->run($flow, ['cart_items' => [
            ['id' => $this->p1['id'], 'quantity' => 2, 'price' => 3.5],
            ['id' => $this->p2['id'], 'quantity' => 1, 'price' => 4.0],
        ]]);

        $order = (array) $this->rq->list($this->orders, [])->items()[0]->toArray();

        expect($this->rq->list($this->orders, [])->total())->toBe(1)
            ->and($order['order_number'])->toBe('ORD-1')                                   // bare-EL concat ran
            ->and($order['status'])->toBe('completed')                                     // literal untouched
            ->and($this->rq->list($this->orderItems, [])->total())->toBe(2)                // line items written
            ->and((int) $this->rq->find($this->products, $this->p1['id'])->toArray()['stock'])->toBe(3)  // 5-2
            ->and((int) $this->rq->find($this->products, $this->p2['id'])->toArray()['stock'])->toBe(7)  // 8-1
            ->and((float) $this->rq->list($this->orderItems, [])->items()[0]->toArray()['line_subtotal'])->toBe(7.0)
            ->and(collect($ctx->actions)->pluck('message'))->toContain('Order placed!');
    });
});
