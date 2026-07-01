<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Models\RecordRevision;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;

/**
 * Define a `leads` collection with a few fields and sync its real table.
 * Mirrors DataModelTest's helper so history is exercised against a real
 * managed collection.
 */
function makeHistoryLeadsModel(array $overrides = []): PbModel
{
    $model = PbModel::create(array_merge([
        'key' => 'leads',
        'table_name' => PbModel::physicalTableName('leads'),
        'name' => 'Leads',
        'has_timestamps' => true,
        'has_soft_deletes' => false,
    ], $overrides));

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

it('writes a created revision on record create', function (): void {
    $model = makeHistoryLeadsModel();

    $record = app(RecordQuery::class)->create($model, [
        'name' => 'Acme', 'email' => 'a@acme.com', 'score' => 10, 'status' => 'open',
    ]);

    $rev = RecordRevision::query()->latest('id')->first();

    expect(RecordRevision::query()->count())->toBe(1)
        ->and($rev->collection)->toBe('leads')
        ->and($rev->record_id)->toBe((string) $record->id)
        ->and($rev->operation)->toBe(RecordRevision::OP_CREATED)
        ->and($rev->before)->toBeNull()
        ->and($rev->after)->toBeArray()
        ->and($rev->after['name'])->toBe('Acme')
        ->and($rev->after['score'])->toBe(10)
        ->and($rev->changed_by)->toBeNull();
});

it('writes before + after on record update', function (): void {
    $model = makeHistoryLeadsModel();
    $q = app(RecordQuery::class);

    $record = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);
    $q->update($model, $record->id, ['status' => 'won']);

    $rev = RecordRevision::query()->where('operation', RecordRevision::OP_UPDATED)->first();

    expect($rev)->not->toBeNull()
        ->and($rev->before)->toBeArray()
        ->and($rev->before['status'])->toBe('open')
        ->and($rev->after)->toBeArray()
        ->and($rev->after['status'])->toBe('won');
});

it('writes before on record delete', function (): void {
    $model = makeHistoryLeadsModel();
    $q = app(RecordQuery::class);

    $record = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);
    $id = $record->id;

    $q->delete($model, $id);

    $rev = RecordRevision::query()->where('operation', RecordRevision::OP_DELETED)->first();

    expect($rev)->not->toBeNull()
        ->and($rev->record_id)->toBe((string) $id)
        ->and($rev->before)->toBeArray()
        ->and($rev->before['name'])->toBe('Acme')
        ->and($rev->after)->toBeNull();
});

it('writes no revision when data.record_history is off', function (): void {
    config()->set('ai-page-builder.data.record_history', false);

    $model = makeHistoryLeadsModel();
    $q = app(RecordQuery::class);

    $record = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);
    $q->update($model, $record->id, ['status' => 'won']);
    $q->delete($model, $record->id);

    expect(RecordRevision::query()->count())->toBe(0);
});

it('does not snapshot writes to a read-only (unmanaged) collection', function (): void {
    // A managed but read-only collection: RecordQuery::create still writes the
    // row (the read-only gate lives at the HTTP edge), but history must skip it.
    $model = PbModel::create([
        'key' => 'reports',
        'name' => 'Reports',
        'table_name' => PbModel::physicalTableName('reports'),
        'is_read_only' => true,
        'has_timestamps' => false,
    ]);
    $model->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'string', 'sort' => 0]);
    app(SchemaSynchronizer::class)->sync($model->fresh());

    $record = app(RecordQuery::class)->create($model->fresh(), ['title' => 'Q2']);

    expect($record)->toBeInstanceOf(Record::class)
        ->and(RecordRevision::query()->count())->toBe(0);
});

it('stamps changed_by from the acting pb-guard user', function (): void {
    $user = PbUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => bcrypt('secret'),
    ]);

    auth()->guard((string) config('ai-page-builder.auth.guard', 'pb'))->setUser($user);

    $model = makeHistoryLeadsModel();
    app(RecordQuery::class)->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    $rev = RecordRevision::query()->latest('id')->first();

    expect($rev->changed_by)->toBe((int) $user->id);
});

it('restores a prior state by re-applying before on an updated revision', function (): void {
    $model = makeHistoryLeadsModel();
    $q = app(RecordQuery::class);

    $record = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);
    $q->update($model, $record->id, ['status' => 'won']);

    $rev = RecordRevision::query()->where('operation', RecordRevision::OP_UPDATED)->first();

    // Re-apply the before snapshot (mirrors the resource's restore action).
    $payload = collect($rev->before)->except(['id', 'created_at', 'updated_at', 'deleted_at'])->all();
    $q->update($model, $rev->record_id, $payload);

    expect($q->find($model, $record->id)->status)->toBe('open');
});

it('recreates a deleted record by re-applying before', function (): void {
    $model = makeHistoryLeadsModel();
    $q = app(RecordQuery::class);

    $record = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);
    $id = $record->id;
    $q->delete($model, $id);

    expect($q->find($model, $id))->toBeNull();

    $rev = RecordRevision::query()->where('operation', RecordRevision::OP_DELETED)->first();
    $payload = collect($rev->before)->except(['id', 'created_at', 'updated_at', 'deleted_at'])->all();
    $recreated = $q->create($model, $payload);

    expect($recreated->name)->toBe('Acme')
        ->and($recreated->status)->toBe('open');
});
