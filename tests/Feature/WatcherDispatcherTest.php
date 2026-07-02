<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Flow\WatcherDispatcher;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\FlowRun;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Watcher;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;

/**
 * Define a `leads` collection and sync its real table (mirrors DataModelTest).
 */
function makeWatchedLeadsModel(): PbModel
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
 * A minimal active flow whose single notify node makes each run observable
 * (FlowManager writes a FlowRun row regardless).
 */
function makeNotifyFlow(string $slug): Flow
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
function makeCollectionWatcher(string $collection, string $event, string $targetSlug, array $config = [], bool $active = true): Watcher
{
    return Watcher::create([
        'name' => "$collection $event → $targetSlug",
        'source_type' => 'collection',
        'source_key' => $collection,
        'event' => $event,
        'config' => $config === [] ? null : $config,
        'target_type' => 'flow',
        'target_key' => $targetSlug,
        'is_active' => $active,
    ]);
}

it('runs the watcher target when a matching record event fires', function (): void {
    $model = makeWatchedLeadsModel();
    makeNotifyFlow('on-create');
    makeCollectionWatcher('leads', 'created', 'on-create');

    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    $run = FlowRun::where('flow_slug_snapshot', 'on-create')->first();
    expect($run)->not->toBeNull()
        ->and($run->input['event'])->toBe('created')
        ->and($run->input['collection'])->toBe('leads')
        ->and($run->input['record']['name'])->toBe('Acme');
});

it('routes each event to its own target (the per-event gap)', function (): void {
    $model = makeWatchedLeadsModel();
    makeNotifyFlow('on-create');
    makeNotifyFlow('on-update');
    makeCollectionWatcher('leads', 'created', 'on-create');
    makeCollectionWatcher('leads', 'updated', 'on-update');
    $q = app(RecordQuery::class);

    $rec = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);
    $q->update($model, $rec->id, ['status' => 'won']);

    // created fired only on-create; updated fired only on-update.
    expect(FlowRun::where('flow_slug_snapshot', 'on-create')->count())->toBe(1)
        ->and(FlowRun::where('flow_slug_snapshot', 'on-update')->count())->toBe(1)
        ->and(FlowRun::where('flow_slug_snapshot', 'on-create')->where('input->event', 'updated')->count())->toBe(0);
});

it('does not fire for an unwatched event, collection, or inactive watcher', function (): void {
    $model = makeWatchedLeadsModel();
    makeNotifyFlow('on-deleted');
    makeNotifyFlow('other-coll');
    makeNotifyFlow('inactive');
    makeCollectionWatcher('leads', 'deleted', 'on-deleted');      // wrong event for a create
    makeCollectionWatcher('contacts', 'created', 'other-coll');   // wrong collection
    makeCollectionWatcher('leads', 'created', 'inactive', active: false);

    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    expect(FlowRun::where('flow_slug_snapshot', 'on-deleted')->count())->toBe(0)
        ->and(FlowRun::where('flow_slug_snapshot', 'other-coll')->count())->toBe(0)
        ->and(FlowRun::where('flow_slug_snapshot', 'inactive')->count())->toBe(0);
});

it('only fires when the record matches ALL criteria', function (): void {
    $model = makeWatchedLeadsModel();
    makeNotifyFlow('on-hot-lead');
    makeCollectionWatcher('leads', 'created', 'on-hot-lead', [
        'criteria' => ['status' => ['eq' => 'won'], 'score' => ['gte' => 50]],
    ]);
    $q = app(RecordQuery::class);

    // status matches but score below threshold -> no run
    $q->create($model, ['name' => 'Low', 'email' => 'l@x.com', 'status' => 'won', 'score' => 10]);
    expect(FlowRun::where('flow_slug_snapshot', 'on-hot-lead')->count())->toBe(0);

    // both criteria satisfied -> runs
    $q->create($model, ['name' => 'High', 'email' => 'h@x.com', 'status' => 'won', 'score' => 80]);
    expect(FlowRun::where('flow_slug_snapshot', 'on-hot-lead')->count())->toBe(1);
});

it('forwards the previous record state as input.old on update', function (): void {
    $model = makeWatchedLeadsModel();
    makeNotifyFlow('on-update');
    makeCollectionWatcher('leads', 'updated', 'on-update');
    $q = app(RecordQuery::class);

    $rec = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);
    $q->update($model, $rec->id, ['status' => 'won']);

    $run = FlowRun::where('flow_slug_snapshot', 'on-update')->first();
    expect($run->input['old']['status'])->toBe('open')
        ->and($run->input['record']['status'])->toBe('won');
});

it('runs a function target through the function runtime', function (): void {
    $ran = [];

    /** @var FunctionRegistry $registry */
    $registry = app(FunctionRegistry::class);
    $registry->register('watch-call', function (array $args) use (&$ran): string {
        $ran[] = $args;

        return 'done';
    });

    FlowFunction::create([
        'slug' => 'ping',
        'name' => 'Ping',
        'runtime' => 'callable',
        'body' => 'watch-call',
    ]);

    $model = makeWatchedLeadsModel();
    Watcher::create([
        'name' => 'lead created → ping',
        'source_type' => 'collection',
        'source_key' => 'leads',
        'event' => 'created',
        'target_type' => 'function',
        'target_key' => 'ping',
        'is_active' => true,
    ]);

    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    expect($ran)->toHaveCount(1)
        ->and($ran[0]['event'])->toBe('created')
        ->and($ran[0]['record']['name'])->toBe('Acme');
});

it('stamps last_fired_at / last_status on the watcher', function (): void {
    $model = makeWatchedLeadsModel();
    makeNotifyFlow('on-create');
    $watcher = makeCollectionWatcher('leads', 'created', 'on-create');

    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    $watcher->refresh();
    expect($watcher->last_status)->toBe('ok')
        ->and($watcher->last_fired_at)->not->toBeNull();
});

it('bounds re-entrant dispatch with a depth guard', function (): void {
    $dispatcher = app(WatcherDispatcher::class);
    $ref = new ReflectionClass($dispatcher);
    $depth = $ref->getProperty('depth');
    $depth->setAccessible(true);
    $depth->setValue(null, 3);

    makeNotifyFlow('on-create');
    makeCollectionWatcher('leads', 'created', 'on-create');

    $dispatcher->dispatchCollectionEvent('leads', 'created', ['name' => 'Acme']);

    expect(FlowRun::where('flow_slug_snapshot', 'on-create')->count())->toBe(0);

    $depth->setValue(null, 0);
});

it('fires a state watcher on a matching change (from → to)', function (): void {
    makeNotifyFlow('on-state');
    Watcher::create([
        'name' => 'status open→won',
        'source_type' => 'state',
        'source_key' => 'status',
        'event' => 'changed',
        'config' => ['from' => 'open', 'to' => 'won'],
        'target_type' => 'flow',
        'target_key' => 'on-state',
        'is_active' => true,
    ]);

    $dispatcher = app(WatcherDispatcher::class);

    // Non-matching transition -> no run.
    $dispatcher->dispatchStateChange('status', 'open', 'lost');
    expect(FlowRun::where('flow_slug_snapshot', 'on-state')->count())->toBe(0);

    // Matching transition -> runs, with old/new in input.
    $dispatcher->dispatchStateChange('status', 'open', 'won');
    $run = FlowRun::where('flow_slug_snapshot', 'on-state')->first();
    expect($run)->not->toBeNull()
        ->and($run->input['old'])->toBe('open')
        ->and($run->input['new'])->toBe('won');
});

it('back-fills legacy collection flows into watchers', function (): void {
    $model = makeWatchedLeadsModel();

    // A legacy collection flow with its binding still in trigger_config.
    Flow::create([
        'slug' => 'legacy-lead',
        'name' => 'Legacy lead',
        'trigger_type' => 'collection',
        'trigger_config' => ['collection' => 'leads', 'events' => ['created', 'updated'], 'criteria' => ['status' => ['eq' => 'won']]],
        'is_active' => true,
        'definition' => [
            'start' => 'n1',
            'nodes' => [
                'n1' => ['type' => 'trigger', 'next' => ['n2']],
                'n2' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'fired']]]],
            ],
        ],
    ]);

    // Run the back-fill migration.
    $migration = include __DIR__.'/../../database/migrations/backfill_collection_flows_into_watchers.php';
    $migration->up();

    // One watcher per listened event, carrying the criteria.
    $watchers = Watcher::where('target_key', 'legacy-lead')->get();
    expect($watchers)->toHaveCount(2)
        ->and($watchers->pluck('event')->sort()->values()->all())->toBe(['created', 'updated'])
        ->and($watchers->firstWhere('event', 'created')->config)->toBe(['criteria' => ['status' => ['eq' => 'won']]]);

    // Idempotent: a second run creates no duplicates.
    $migration->up();
    expect(Watcher::where('target_key', 'legacy-lead')->count())->toBe(2);

    // And the migrated automation actually fires (criteria enforced).
    app(RecordQuery::class)->create($model, ['name' => 'Winner', 'email' => 'w@x.com', 'status' => 'won']);
    expect(FlowRun::where('flow_slug_snapshot', 'legacy-lead')->count())->toBe(1);
});
