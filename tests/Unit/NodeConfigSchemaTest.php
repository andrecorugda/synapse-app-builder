<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Nodes\ConditionNode;
use Andre\AiPageBuilder\Flow\Nodes\LoopNode;
use Andre\AiPageBuilder\Flow\Nodes\RecordNode;
use Andre\AiPageBuilder\Flow\Nodes\ResultNode;
use Andre\AiPageBuilder\Flow\Nodes\TransactionNode;
use Andre\AiPageBuilder\Flow\ResultActionCatalog;

// ── CapabilityInput ────────────────────────────────────────────────────────────

it('serialises showIf into toArray()', function (): void {
    $input = new CapabilityInput(
        key: 'id',
        label: 'Record ID',
        type: 'expression',
        showIf: ['operation' => ['get', 'update', 'delete']],
    );

    $arr = $input->toArray();

    expect($arr)->toHaveKey('show_if')
        ->and($arr['show_if'])->toBe(['operation' => ['get', 'update', 'delete']]);
});

it('defaults showIf to an empty array when not provided', function (): void {
    $input = new CapabilityInput('key', 'Label', 'string');

    expect($input->toArray()['show_if'])->toBe([]);
});

it('keeps showIf backward-compatible — all prior positional args still work', function (): void {
    // Constructing with all named args that existed before showIf was added
    $input = new CapabilityInput(
        key: 'op',
        label: 'Operator',
        type: 'select',
        required: false,
        default: 'equals',
        help: 'Choose an operator',
        options: ['equals' => 'Equals'],
        interpolated: true,
    );

    expect($input->showIf)->toBe([]);
    expect($input->toArray()['show_if'])->toBe([]);
});

// ── TransactionNode ────────────────────────────────────────────────────────────

it('TransactionNode definition has a body input', function (): void {
    $node = new TransactionNode;
    $def = $node->definition();

    $inputs = collect($def->inputs)->keyBy(fn (CapabilityInput $i): string => $i->key);

    expect($inputs->has('body'))->toBeTrue('TransactionNode is missing the body input');
    expect($inputs->get('body')->type)->toBe('json');
});

it('TransactionNode definition has the expected output handles', function (): void {
    $node = new TransactionNode;
    $def = $node->definition();

    expect($def->outputHandles)->toContain('committed')
        ->and($def->outputHandles)->toContain('rolled_back')
        ->and($def->outputHandles)->toContain('body');
});

// ── LoopNode ──────────────────────────────────────────────────────────────────

it('LoopNode definition exposes over, item_var, index_var, and body inputs', function (): void {
    $node = new LoopNode;
    $def = $node->definition();

    $keys = collect($def->inputs)->pluck('key')->all();

    expect($keys)->toContain('over')
        ->and($keys)->toContain('item_var')
        ->and($keys)->toContain('index_var')
        ->and($keys)->toContain('body');
});

it('LoopNode body input is type json', function (): void {
    $node = new LoopNode;
    $def = $node->definition();

    $body = collect($def->inputs)->firstWhere(fn (CapabilityInput $i): bool => $i->key === 'body');

    expect($body)->not->toBeNull()
        ->and($body->type)->toBe('json');
});

// ── ResultActionCatalog ────────────────────────────────────────────────────────

it('ResultActionCatalog::types() returns a non-empty map', function (): void {
    $types = ResultActionCatalog::types();

    expect($types)->not->toBeEmpty();
});

it('ResultActionCatalog includes all expected action type keys', function (string $expected): void {
    expect(ResultActionCatalog::types())->toHaveKey($expected);
})->with(['notify', 'alert', 'modal', 'redirect', 'setState', 'setHtml', 'setText', 'addClass', 'removeClass', 'logout']);

it('each ResultActionCatalog entry has a label string and a non-empty fields list', function (string $type, array $descriptor): void {
    expect($descriptor)->toHaveKey('label')
        ->and($descriptor['label'])->toBeString()
        ->and($descriptor)->toHaveKey('fields')
        ->and($descriptor['fields'])->not->toBeEmpty();
})->with(fn () => array_map(
    fn (string $type, array $d): array => [$type, $d],
    array_keys(ResultActionCatalog::types()),
    array_values(ResultActionCatalog::types()),
));

it('each ResultActionCatalog field descriptor has key, label, and type keys', function (string $type, array $field): void {
    expect($field)->toHaveKey('key')
        ->and($field)->toHaveKey('label')
        ->and($field)->toHaveKey('type');
})->with(function (): Generator {
    foreach (ResultActionCatalog::types() as $type => $descriptor) {
        foreach ($descriptor['fields'] as $field) {
            yield "{$type}:{$field['key']}" => [$type, $field];
        }
    }
});

// ── ResultNode wires the catalog into its actions input ───────────────────────

it('ResultNode actions input is type actions and carries the catalog in options', function (): void {
    $node = new ResultNode;
    $def = $node->definition();

    $actionsInput = collect($def->inputs)
        ->first(fn (CapabilityInput $i): bool => $i->key === 'actions');

    expect($actionsInput)->not->toBeNull()
        ->and($actionsInput->type)->toBe('actions')
        ->and($actionsInput->options)->toHaveKey('notify')
        ->and($actionsInput->options)->toHaveKey('redirect');
});

it('ResultNode actions input options match the full ResultActionCatalog', function (): void {
    $node = new ResultNode;
    $def = $node->definition();

    $actionsInput = collect($def->inputs)
        ->first(fn (CapabilityInput $i): bool => $i->key === 'actions');

    expect($actionsInput->options)->toBe(ResultActionCatalog::types());
});

// ── showIf on RecordNode ───────────────────────────────────────────────────────

it('RecordNode id input has showIf restricting it to get/update/delete', function (): void {
    $node = app(RecordNode::class);
    $def = $node->definition();

    $idInput = collect($def->inputs)->firstWhere(fn (CapabilityInput $i): bool => $i->key === 'id');

    expect($idInput)->not->toBeNull()
        ->and($idInput->showIf)->toHaveKey('operation')
        ->and($idInput->showIf['operation'])->toContain('get')
        ->and($idInput->showIf['operation'])->toContain('update')
        ->and($idInput->showIf['operation'])->toContain('delete')
        ->and($idInput->showIf['operation'])->not->toContain('list')
        ->and($idInput->showIf['operation'])->not->toContain('create');
});

it('RecordNode exposes list-only inputs (filter, search, sort, per_page, page)', function (string $expected): void {
    $node = app(RecordNode::class);
    $def = $node->definition();

    $keys = collect($def->inputs)->pluck('key')->all();

    expect($keys)->toContain($expected);
})->with(['filter', 'search', 'sort', 'per_page', 'page']);

// ── showIf on ConditionNode ────────────────────────────────────────────────────

it('ConditionNode right input has showIf excluding empty/not_empty operators', function (): void {
    $node = new ConditionNode;
    $def = $node->definition();

    $rightInput = collect($def->inputs)->firstWhere(fn (CapabilityInput $i): bool => $i->key === 'right');

    expect($rightInput)->not->toBeNull()
        ->and($rightInput->showIf)->toHaveKey('op')
        ->and($rightInput->showIf['op'])->toContain('equals')
        ->and($rightInput->showIf['op'])->not->toContain('empty')
        ->and($rightInput->showIf['op'])->not->toContain('not_empty');
});
