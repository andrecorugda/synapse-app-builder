<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowDispatcher;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowRun;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;

/**
 * Define a `leads` collection and sync its real table (mirrors DataModelTest).
 */
function makeTriggerLeadsModel(): PbModel
{
    $model = PbModel::create([
        'key' => 'leads',
        'table_name' => PbModel::physicalTableName('leads'),
        'name' => 'Leads',
        'has_timestamps' => true,
        'has_soft_deletes' => false,
    ]);

    $fields = [
        ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
        ['key' => 'email', 'label' => 'Email', 'type' => 'string'],
        ['key' => 'score', 'label' => 'Score', 'type' => 'integer'],
        ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['choices' => ['open', 'won', 'lost']]],
    ];
    foreach ($fields as $i => $f) {
        $model->fields()->create($f + ['sort' => $i]);
    }

    app(SchemaSynchronizer::class)->sync($model->fresh());

    return $model->fresh();
}

/**
 * An active collection-triggered flow. A single result/notify node makes the
 * run observable, and FlowManager records a FlowRun row regardless.
 *
 * @param  array<string,mixed>  $triggerConfig
 */
function makeCollectionFlow(array $triggerConfig, string $slug = 'on-lead'): Flow
{
    return Flow::create([
        'slug' => $slug,
        'name' => 'On lead',
        'trigger_type' => 'collection',
        'is_active' => true,
        'trigger_config' => $triggerConfig,
        'definition' => [
            'start' => 'n1',
            'nodes' => [
                'n1' => ['type' => 'trigger', 'next' => ['n2']],
                'n2' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'fired']]]],
            ],
        ],
    ]);
}

it('runs a collection flow when a matching record is created', function (): void {
    $model = makeTriggerLeadsModel();
    $flow = makeCollectionFlow(['collection' => 'leads', 'events' => ['created']]);

    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    $run = FlowRun::where('flow_id', $flow->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->trigger_type)->toBe('collection')
        ->and($run->input['event'])->toBe('created')
        ->and($run->input['collection'])->toBe('leads')
        ->and($run->input['record']['name'])->toBe('Acme');
});

it('does not run for an event the flow is not listening for', function (): void {
    $model = makeTriggerLeadsModel();
    $flow = makeCollectionFlow(['collection' => 'leads', 'events' => ['deleted']]);

    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    expect(FlowRun::where('flow_id', $flow->id)->count())->toBe(0);
});

it('does not run for a different collection', function (): void {
    $model = makeTriggerLeadsModel();
    $flow = makeCollectionFlow(['collection' => 'contacts', 'events' => ['created']]);

    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    expect(FlowRun::where('flow_id', $flow->id)->count())->toBe(0);
});

it('skips inactive collection flows', function (): void {
    $model = makeTriggerLeadsModel();
    $flow = makeCollectionFlow(['collection' => 'leads', 'events' => ['created']]);
    $flow->update(['is_active' => false]);

    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    expect(FlowRun::where('flow_id', $flow->id)->count())->toBe(0);
});

it('only fires when the record matches ALL criteria', function (): void {
    $model = makeTriggerLeadsModel();
    $flow = makeCollectionFlow([
        'collection' => 'leads',
        'events' => ['created'],
        'criteria' => ['status' => ['eq' => 'won'], 'score' => ['gte' => 50]],
    ]);
    $q = app(RecordQuery::class);

    // status matches but score is below threshold -> no run
    $q->create($model, ['name' => 'Low', 'email' => 'l@x.com', 'status' => 'won', 'score' => 10]);
    expect(FlowRun::where('flow_id', $flow->id)->count())->toBe(0);

    // both criteria satisfied -> runs
    $q->create($model, ['name' => 'High', 'email' => 'h@x.com', 'status' => 'won', 'score' => 80]);
    expect(FlowRun::where('flow_id', $flow->id)->count())->toBe(1);
});

it('fires on update and delete events', function (): void {
    $model = makeTriggerLeadsModel();
    $flow = makeCollectionFlow(['collection' => 'leads', 'events' => ['updated', 'deleted']]);
    $q = app(RecordQuery::class);

    $rec = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);
    // create is not in events -> no run yet
    expect(FlowRun::where('flow_id', $flow->id)->count())->toBe(0);

    $q->update($model, $rec->id, ['status' => 'won']);
    expect(FlowRun::where('flow_id', $flow->id)->where('input->event', 'updated')->count())->toBe(1);

    $q->delete($model, $rec->id);
    expect(FlowRun::where('flow_id', $flow->id)->where('input->event', 'deleted')->count())->toBe(1);
});

it('bounds re-entrant dispatch with a depth guard', function (): void {
    // Reaching MAX_DEPTH must short-circuit rather than recurse without bound.
    $dispatcher = app(FlowDispatcher::class);
    $ref = new ReflectionClass($dispatcher);
    $depth = $ref->getProperty('depth');
    $depth->setAccessible(true);
    $depth->setValue(null, 3);

    $model = makeTriggerLeadsModel();
    $flow = makeCollectionFlow(['collection' => 'leads', 'events' => ['created']]);

    $dispatcher->dispatchCollectionEvent('leads', 'created', ['name' => 'Acme']);

    expect(FlowRun::where('flow_id', $flow->id)->count())->toBe(0);

    $depth->setValue(null, 0);
});
