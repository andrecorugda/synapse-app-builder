<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\Nodes\RecordNode;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Define a `leads` collection with a few fields and sync its real table.
 */
function makeLeadsModel(array $overrides = []): PbModel
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
        ['key' => 'email', 'label' => 'Email', 'type' => 'string', 'options' => ['unique' => true]],
        ['key' => 'score', 'label' => 'Score', 'type' => 'integer'],
        ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['choices' => ['open', 'won', 'lost']]],
    ];
    foreach ($fields as $i => $f) {
        $model->fields()->create($f + ['sort' => $i]);
    }

    app(SchemaSynchronizer::class)->sync($model->fresh());

    return $model->fresh();
}

// ---------------------------------------------------------------------------
// SchemaSynchronizer
// ---------------------------------------------------------------------------

it('creates a real prefixed table from a model definition', function (): void {
    $model = makeLeadsModel();

    expect(Schema::hasTable('pb_leads'))->toBeTrue()
        ->and(Schema::hasColumns('pb_leads', ['id', 'name', 'email', 'score', 'status', 'created_at', 'updated_at']))->toBeTrue();
});

it('adds a column when a new field is added to an existing model', function (): void {
    $model = makeLeadsModel();
    $model->fields()->create(['key' => 'phone', 'label' => 'Phone', 'type' => 'string', 'sort' => 9]);

    app(SchemaSynchronizer::class)->sync($model->fresh());

    expect(Schema::hasColumn('pb_leads', 'phone'))->toBeTrue();
});

it('alters the physical column when a field type changes (string → integer)', function (): void {
    $model = makeLeadsModel();
    expect(FieldType::normalizeDbType(Schema::getColumnType('pb_leads', 'name')))->toBe('string');

    // Author edits the "name" field from string to integer.
    $field = $model->fields()->where('key', 'name')->firstOrFail();
    $field->update(['type' => 'integer']);

    app(SchemaSynchronizer::class)->sync($model->fresh());

    expect(FieldType::normalizeDbType(Schema::getColumnType('pb_leads', 'name')))->toBe('integer');
});

it('does not alter a column when the field type is unchanged (idempotent sync)', function (): void {
    $model = makeLeadsModel();

    // A no-op edit (relabel only) must not change the column's storage type.
    $model->fields()->where('key', 'score')->firstOrFail()->update(['label' => 'Lead Score']);
    app(SchemaSynchronizer::class)->sync($model->fresh());

    expect(FieldType::normalizeDbType(Schema::getColumnType('pb_leads', 'score')))->toBe('integer')
        ->and(Schema::hasColumns('pb_leads', ['name', 'email', 'score', 'status']))->toBeTrue();
});

it('drops the physical table on dropTable', function (): void {
    $model = makeLeadsModel();
    app(SchemaSynchronizer::class)->dropTable($model);

    expect(Schema::hasTable('pb_leads'))->toBeFalse();
});

it('drops the physical column on dropColumnFor (explicit field delete)', function (): void {
    $model = makeLeadsModel();
    expect(Schema::hasColumn('pb_leads', 'score'))->toBeTrue();

    // Explicit removal drops the column regardless of allow_destructive_sync
    // (which defaults false) — this is the admin "delete field" path.
    config()->set('ai-page-builder.data.allow_destructive_sync', false);
    app(SchemaSynchronizer::class)->dropColumnFor($model, 'score');

    expect(Schema::hasColumn('pb_leads', 'score'))->toBeFalse();
});

it('dropColumnFor never drops system columns and no-ops on a missing column', function (): void {
    $model = makeLeadsModel();
    app(SchemaSynchronizer::class)->dropColumnFor($model, 'id');
    app(SchemaSynchronizer::class)->dropColumnFor($model, 'created_at');
    app(SchemaSynchronizer::class)->dropColumnFor($model, 'does_not_exist');

    expect(Schema::hasColumns('pb_leads', ['id', 'created_at']))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Record + RecordQuery
// ---------------------------------------------------------------------------

it('creates records with field-derived casts', function (): void {
    $model = makeLeadsModel();
    $record = app(RecordQuery::class)->create($model, [
        'name' => 'Acme', 'email' => 'a@acme.com', 'score' => '42', 'status' => 'open',
    ]);

    expect($record)->toBeInstanceOf(Record::class)
        ->and($record->score)->toBe(42); // integer cast applied
});

it('filters, sorts and projects fields Directus-style', function (): void {
    $model = makeLeadsModel();
    $q = app(RecordQuery::class);
    $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'score' => 42, 'status' => 'open']);
    $q->create($model, ['name' => 'Globex', 'email' => 'g@globex.com', 'score' => 88, 'status' => 'won']);

    $page = $q->list($model, ['filter' => ['score' => ['gte' => 50]], 'sort' => '-score']);
    expect($page->total())->toBe(1)
        ->and($page->items()[0]->name)->toBe('Globex');

    $projected = $q->find($model, $q->list($model)->items()[0]->id, ['fields' => 'id,name']);
    expect($projected->toArray())->not->toHaveKey('email');
});

it('searches across text fields', function (): void {
    $model = makeLeadsModel();
    $q = app(RecordQuery::class);
    $q->create($model, ['name' => 'Globex', 'email' => 'g@globex.com', 'status' => 'won']);
    $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    expect($q->list($model, ['search' => 'glob'])->total())->toBe(1);
});

it('updates partially and deletes', function (): void {
    $model = makeLeadsModel();
    $q = app(RecordQuery::class);
    $rec = $q->create($model, ['name' => 'Acme', 'email' => 'a@acme.com', 'status' => 'open']);

    $q->update($model, $rec->id, ['status' => 'lost']);
    expect($q->find($model, $rec->id)->status)->toBe('lost');

    expect($q->delete($model, $rec->id))->toBeTrue()
        ->and($q->list($model)->total())->toBe(0);
});

it('enforces validation rules from field definitions', function (): void {
    $model = makeLeadsModel();

    expect(fn () => app(RecordQuery::class)->create($model, ['status' => 'open']))
        ->toThrow(ValidationException::class); // name is required

    expect(fn () => app(RecordQuery::class)->create($model, ['name' => 'X', 'status' => 'invalid']))
        ->toThrow(ValidationException::class); // status not in choices
});

// ---------------------------------------------------------------------------
// Auto REST API
// ---------------------------------------------------------------------------

it('exposes CRUD over the REST API', function (): void {
    $model = makeLeadsModel();

    $this->postJson('/api/pb/leads', ['name' => 'Initech', 'email' => 'i@initech.com', 'score' => 55, 'status' => 'open'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Initech');

    $this->getJson('/api/pb/leads?filter[score][gte]=50&sort=-score')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.name', 'Initech');

    $this->postJson('/api/pb/leads', ['status' => 'open'])->assertStatus(422);
    $this->getJson('/api/pb/unknown-model')->assertNotFound();
});

// ---------------------------------------------------------------------------
// Flow Record node
// ---------------------------------------------------------------------------

it('reads and writes records from a flow node', function (): void {
    $model = makeLeadsModel();
    $node = app(RecordNode::class);

    // create
    $ctx = new FlowContext(['n' => 'Wayne']);
    $node->run([
        'config' => ['model' => 'leads', 'operation' => 'create', 'data' => ['name' => '{{ input.n }}', 'status' => 'open'], 'output' => 'created'],
        'next' => ['x'],
    ], $ctx);
    expect($ctx->vars['created']['name'])->toBe('Wayne');

    // list
    $ctx2 = new FlowContext;
    $next = $node->run([
        'config' => ['model' => 'leads', 'operation' => 'list', 'filter' => ['status' => ['eq' => 'open']], 'output' => 'rows'],
        'next' => ['done'],
    ], $ctx2);
    expect($ctx2->vars['rows'])->toHaveCount(1)
        ->and($next)->toBe(['done']);
});

// ── QA regressions: data-layer robustness ──────────────────────────────────

it('rejects a duplicate unique value with a validation error, not a 500', function (): void {
    $model = makeLeadsModel();
    $q = app(RecordQuery::class);
    $q->create($model, ['name' => 'A', 'email' => 'dup@x.com']);

    // A second row with the same unique email must raise ValidationException
    // (→ 422), never a raw QueryException (→ 500).
    expect(fn () => $q->create($model, ['name' => 'B', 'email' => 'dup@x.com']))
        ->toThrow(ValidationException::class);
});

it('lets a unique field keep its own value on update (ignore self)', function (): void {
    $model = makeLeadsModel();
    $q = app(RecordQuery::class);
    $rec = $q->create($model, ['name' => 'A', 'email' => 'a@x.com']);

    // Updating the same row without changing the unique value must not trip the
    // unique rule against itself.
    $updated = $q->update($model, $rec->getKey(), ['name' => 'A2', 'email' => 'a@x.com']);
    expect($updated)->not->toBeNull()
        ->and($updated->getAttribute('name'))->toBe('A2');
});

it('ignores a malformed between filter (one bound) instead of erroring', function (): void {
    $model = makeLeadsModel();
    $q = app(RecordQuery::class);
    $q->create($model, ['name' => 'A', 'email' => 'a@x.com', 'score' => 5]);
    $q->create($model, ['name' => 'B', 'email' => 'b@x.com', 'score' => 50]);

    // A single-value between used to throw (bound-count mismatch, HTTP 500).
    // Now it's ignored → the query runs and returns all rows.
    $res = $q->list($model, ['filter' => ['score' => ['between' => '5']]]);
    expect($res->total())->toBe(2);

    // A valid two-value between still filters.
    $res2 = $q->list($model, ['filter' => ['score' => ['between' => '0,10']]]);
    expect($res2->total())->toBe(1);
});
