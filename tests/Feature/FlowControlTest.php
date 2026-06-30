<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Flow\NodeRegistry;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;

/**
 * Phase 2 — loop + transaction flow-control nodes. These prove the engine can
 * run a body sub-graph repeatedly (loop) and atomically (transaction) against
 * REAL collection rows, which is what makes an eval-free POS checkout possible.
 */
function makeWidgets(): PbModel
{
    $model = PbModel::create([
        'key' => 'widgets',
        'table_name' => PbModel::physicalTableName('widgets'),
        'name' => 'Widgets',
        'has_timestamps' => true,
        'has_soft_deletes' => false,
    ]);

    $fields = [
        ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
        ['key' => 'code', 'label' => 'Code', 'type' => 'string', 'options' => ['unique' => true]],
    ];
    foreach ($fields as $i => $f) {
        $model->fields()->create($f + ['sort' => $i]);
    }

    app(SchemaSynchronizer::class)->sync($model->fresh());

    return $model->fresh();
}

/** A record-create node usable inside a body sub-graph. */
function createWidgetNode(string $name, string $code, ?string $next = null): array
{
    $node = [
        'type' => 'record',
        'config' => ['operation' => 'create', 'model' => 'widgets', 'data' => ['name' => $name, 'code' => $code]],
    ];
    if ($next !== null) {
        $node['next'] = [$next];
    }

    return $node;
}

function widgetCount(PbModel $model): int
{
    return Record::for($model)->newQuery()->count();
}

beforeEach(function (): void {
    $this->widgets = makeWidgets();
    $this->runner = app(FlowRunner::class);
});

// --- Loop -------------------------------------------------------------------

it('runs a loop body once per array element', function (): void {
    $definition = [
        'start' => 'loop',
        'nodes' => [
            'loop' => [
                'type' => 'loop',
                'config' => [
                    'over' => 'input.items',
                    'item_var' => 'item',
                    'output' => 'loop_result',
                    'body' => [
                        'start' => 'create',
                        'nodes' => ['create' => createWidgetNode('{{ vars.item }}', '{{ vars.item }}')],
                    ],
                ],
            ],
        ],
    ];

    $ctx = $this->runner->run($definition, ['items' => ['a', 'b', 'c']]);

    expect(widgetCount($this->widgets))->toBe(3)
        ->and($ctx->get('vars.loop_result'))->toBe(['count' => 3])
        ->and($ctx->failed)->toBeFalse();
});

it('caps iterations at max_iterations', function (): void {
    $definition = [
        'start' => 'loop',
        'nodes' => [
            'loop' => [
                'type' => 'loop',
                'config' => [
                    'over' => 'input.items',
                    'max_iterations' => 2,
                    'body' => [
                        'start' => 'create',
                        'nodes' => ['create' => createWidgetNode('{{ vars.item }}', '{{ vars.item }}')],
                    ],
                ],
            ],
        ],
    ];

    $this->runner->run($definition, ['items' => ['a', 'b', 'c', 'd']]);

    expect(widgetCount($this->widgets))->toBe(2);
});

// --- Transaction ------------------------------------------------------------

it('commits a transaction body and follows the committed branch', function (): void {
    $definition = [
        'start' => 'tx',
        'nodes' => [
            'tx' => [
                'type' => 'transaction',
                'committed' => 'done',
                'rolled_back' => 'failed',
                'config' => [
                    'body' => [
                        'start' => 'c1',
                        'nodes' => [
                            'c1' => createWidgetNode('First', 'A', 'c2'),
                            'c2' => createWidgetNode('Second', 'B'),
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'trigger'],
            'failed' => ['type' => 'trigger'],
        ],
    ];

    $ctx = $this->runner->run($definition);
    $ran = collect($ctx->steps)->pluck('node');

    expect(widgetCount($this->widgets))->toBe(2)
        ->and($ran->contains('done'))->toBeTrue()
        ->and($ran->contains('failed'))->toBeFalse();
});

it('rolls back every write when the transaction body fails', function (): void {
    $definition = [
        'start' => 'tx',
        'nodes' => [
            'tx' => [
                'type' => 'transaction',
                'committed' => 'done',
                'rolled_back' => 'failed',
                'config' => [
                    'body' => [
                        'start' => 'c1',
                        'nodes' => [
                            // Second create reuses code 'DUP' → unique violation → throws.
                            'c1' => createWidgetNode('First', 'DUP', 'c2'),
                            'c2' => createWidgetNode('Second', 'DUP'),
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'trigger'],
            'failed' => ['type' => 'trigger'],
        ],
    ];

    $ctx = $this->runner->run($definition);
    $ran = collect($ctx->steps)->pluck('node');

    // The first (valid) insert is rolled back alongside the failing one.
    expect(widgetCount($this->widgets))->toBe(0)
        ->and($ran->contains('failed'))->toBeTrue()
        ->and($ran->contains('done'))->toBeFalse();
});

it('discards UI actions emitted by a rolled-back body', function (): void {
    $definition = [
        'start' => 'tx',
        'nodes' => [
            'tx' => [
                'type' => 'transaction',
                'rolled_back' => 'failed',
                'config' => [
                    'body' => [
                        'start' => 'toast',
                        'nodes' => [
                            'toast' => [
                                'type' => 'result',
                                'config' => ['actions' => [['type' => 'notify', 'message' => 'created!']]],
                                'next' => ['c1'],
                            ],
                            'c1' => createWidgetNode('First', 'DUP', 'c2'),
                            'c2' => createWidgetNode('Second', 'DUP'),
                        ],
                    ],
                ],
            ],
            'failed' => ['type' => 'trigger'],
        ],
    ];

    $ctx = $this->runner->run($definition);

    expect($ctx->actions)->toBe([]);
});

it('rolls back a loop inside a transaction all-or-nothing (POS shape)', function (): void {
    // Cart of three line items; the third reuses an earlier code → fails late,
    // after two successful inserts. The whole order must roll back.
    $definition = [
        'start' => 'tx',
        'nodes' => [
            'tx' => [
                'type' => 'transaction',
                'committed' => 'done',
                'rolled_back' => 'failed',
                'config' => [
                    'body' => [
                        'start' => 'loop',
                        'nodes' => [
                            'loop' => [
                                'type' => 'loop',
                                'config' => [
                                    'over' => 'input.cart',
                                    'body' => [
                                        'start' => 'line',
                                        'nodes' => ['line' => createWidgetNode('{{ vars.item }}', '{{ vars.item }}')],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'done' => ['type' => 'trigger'],
            'failed' => ['type' => 'trigger'],
        ],
    ];

    $ctx = $this->runner->run($definition, ['cart' => ['x', 'y', 'x']]);
    $ran = collect($ctx->steps)->pluck('node');

    expect(widgetCount($this->widgets))->toBe(0)
        ->and($ran->contains('failed'))->toBeTrue();
});

// --- Registry metadata ------------------------------------------------------

it('registers loop and transaction with body output handles', function (): void {
    $defs = collect(app(NodeRegistry::class)->definitions())->keyBy('key');

    expect($defs->has('loop'))->toBeTrue()
        ->and($defs->has('transaction'))->toBeTrue()
        ->and($defs->get('loop')->outputHandles)->toContain('body')
        ->and($defs->get('transaction')->outputHandles)->toContain('committed')
        ->and($defs->get('transaction')->outputHandles)->toContain('rolled_back');
});
