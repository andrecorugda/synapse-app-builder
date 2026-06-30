<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Flow\ExpressionEvaluator;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FlowRuntime;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;

/**
 * Phase 3 — the curated, eval-free helper library exposed in the expression
 * sandbox. These prove the helpers actually run (write through RecordQuery, queue
 * browser actions) and are catalogued for the dropdown / MCP.
 */
function makeHelperWidgets(): PbModel
{
    $model = PbModel::create([
        'key' => 'widgets',
        'table_name' => PbModel::physicalTableName('widgets'),
        'name' => 'Widgets',
        'has_timestamps' => true,
        'has_soft_deletes' => false,
    ]);
    $model->fields()->create(['key' => 'name', 'label' => 'Name', 'type' => 'string', 'sort' => 0, 'options' => ['required' => true]]);
    $model->fields()->create(['key' => 'code', 'label' => 'Code', 'type' => 'string', 'sort' => 1, 'options' => ['unique' => true]]);

    app(SchemaSynchronizer::class)->sync($model->fresh());

    return $model->fresh();
}

function evalExpr(string $expression): mixed
{
    return app(ExpressionEvaluator::class)->evaluate($expression);
}

it('catalogues helpers with helper kind and real categories', function (): void {
    $defs = collect(app(HelperRegistry::class)->definitions());

    expect($defs->pluck('key')->all())
        ->toContain('db_create', 'db_update', 'db_list', 'ui_notify', 'ui_redirect', 'auth_id', 'util_uuid')
        ->and($defs->every(fn (CapabilityDefinition $d): bool => $d->kind === CapabilityDefinition::KIND_HELPER))->toBeTrue()
        ->and($defs->map(fn (CapabilityDefinition $d): string => $d->category->value)->unique()->all())->toContain('data', 'ui', 'auth', 'util');
});

it('runs db_create as a sandbox function that writes a real record', function (): void {
    $model = makeHelperWidgets();

    $row = evalExpr('db_create("widgets", {"name": "Gadget", "code": "G1"})');

    expect($row)->toBeArray()
        ->and($row['name'])->toBe('Gadget')
        ->and(Record::for($model)->newQuery()->count())->toBe(1);
});

it('reads back rows with db_list and db_find', function (): void {
    makeHelperWidgets();
    evalExpr('db_create("widgets", {"name": "A", "code": "a"})');
    $created = evalExpr('db_create("widgets", {"name": "B", "code": "b"})');

    $list = evalExpr('db_list("widgets")');
    $found = evalExpr('db_find("widgets", '.$created['id'].')');

    expect($list)->toHaveCount(2)
        ->and($found['name'])->toBe('B');
});

it('lets ui helpers queue browser actions onto the active run context', function (): void {
    $ctx = new FlowContext;
    app(FlowRuntime::class)->setContext($ctx);

    evalExpr('ui_notify("Saved!", "success")');
    evalExpr('ui_redirect("/p/done")');

    app(FlowRuntime::class)->setContext(null);

    expect($ctx->actions)->toHaveCount(2)
        ->and($ctx->actions[0])->toMatchArray(['type' => 'notify', 'message' => 'Saved!', 'level' => 'success'])
        ->and($ctx->actions[1])->toMatchArray(['type' => 'redirect', 'url' => '/p/done']);
});

it('ui helpers are graceful no-ops outside a flow run', function (): void {
    app(FlowRuntime::class)->setContext(null);

    expect(evalExpr('ui_notify("nobody listening")'))->toBeNull();
});

it('auth helpers report a guest when no end-user is signed in', function (): void {
    expect(evalExpr('auth_id()'))->toBeNull()
        ->and(evalExpr('auth_check()'))->toBeFalse();
});

it('util helpers shape values', function (): void {
    expect(evalExpr('util_number_format(3.14159, 2)'))->toBe('3.14')
        ->and(evalExpr('util_json_decode("{\"a\":1}")'))->toBe(['a' => 1]);
});
