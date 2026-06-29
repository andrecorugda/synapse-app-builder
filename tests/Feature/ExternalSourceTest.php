<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create a raw table the package does NOT manage and seed it directly, then map
 * an EXTERNAL, read-only PbModel onto it. The table lives on the same (default)
 * connection the package uses in tests — "external" here means the package does
 * not OWN the table, not that it lives in a literally separate database.
 */
function makeExternalWidgets(array $overrides = []): PbModel
{
    Schema::create('legacy_widgets', function ($t): void {
        $t->id();
        $t->string('name');
        $t->integer('qty');
    });

    DB::table('legacy_widgets')->insert([
        ['name' => 'Sprocket', 'qty' => 5],
        ['name' => 'Cog', 'qty' => 12],
    ]);

    $model = PbModel::create(array_merge([
        'key' => 'widgets',
        'name' => 'Widgets',
        'table_name' => 'legacy_widgets',
        'source_type' => PbModel::SOURCE_EXTERNAL,
        'source_connection' => null,
        'is_read_only' => true,
        'has_timestamps' => false,
    ], $overrides));

    // Field rows describe the existing columns so RecordQuery whitelists them.
    $model->fields()->create(['key' => 'name', 'label' => 'Name', 'type' => 'string', 'sort' => 0]);
    $model->fields()->create(['key' => 'qty', 'label' => 'Qty', 'type' => 'integer', 'sort' => 1]);

    return $model->fresh();
}

// ---------------------------------------------------------------------------
// 1. Read an existing table the package did not create
// ---------------------------------------------------------------------------

it('reads rows from an existing external table via RecordQuery', function (): void {
    $widgets = makeExternalWidgets();

    $page = app(RecordQuery::class)->list($widgets, ['sort' => 'name']);

    expect($page->total())->toBe(2)
        ->and(collect($page->items())->pluck('name')->all())->toBe(['Cog', 'Sprocket'])
        ->and($page->items()[0]->qty)->toBe(12); // integer cast applied
});

it('finds a single external row by id', function (): void {
    $widgets = makeExternalWidgets();
    $id = (int) DB::table('legacy_widgets')->where('name', 'Sprocket')->value('id');

    $found = app(RecordQuery::class)->find($widgets, $id);

    expect($found)->not->toBeNull()
        ->and($found->name)->toBe('Sprocket')
        ->and($found->qty)->toBe(5);
});

// ---------------------------------------------------------------------------
// 2. SchemaSynchronizer never manages an external table
// ---------------------------------------------------------------------------

it('does not create, alter or drop the external table on sync', function (): void {
    $widgets = makeExternalWidgets();

    app(SchemaSynchronizer::class)->sync($widgets);

    expect(Schema::hasTable('legacy_widgets'))->toBeTrue()
        ->and(Schema::hasColumns('legacy_widgets', ['id', 'name', 'qty']))->toBeTrue()
        // No pb_-prefixed managed table was created for the external collection.
        ->and(Schema::hasTable(PbModel::physicalTableName('widgets')))->toBeFalse()
        // Rows are untouched.
        ->and(DB::table('legacy_widgets')->count())->toBe(2);
});

it('does not drop the external table on dropTable', function (): void {
    $widgets = makeExternalWidgets();

    app(SchemaSynchronizer::class)->dropTable($widgets);

    expect(Schema::hasTable('legacy_widgets'))->toBeTrue()
        ->and(DB::table('legacy_widgets')->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// 3. dataConnection / Record binding
// ---------------------------------------------------------------------------

it('binds Record to the external collection table and reads through it', function (): void {
    $widgets = makeExternalWidgets();

    expect($widgets->isExternal())->toBeTrue()
        ->and($widgets->isReadOnly())->toBeTrue();

    // source_connection is null → dataConnection() falls back to the package
    // connection (null = the default connection in tests). Record binds to it
    // without error and reads the existing table.
    expect($widgets->dataConnection())->toBe(Andre\AiPageBuilder\Support\Schema::connection());

    $record = Record::for($widgets);

    expect($record->getTable())->toBe('legacy_widgets')
        ->and($record->newQuery()->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// 4. Read-only writes blocked via the REST API (external collection)
// ---------------------------------------------------------------------------

it('blocks writes to an external read-only collection over the REST API', function (): void {
    $widgets = makeExternalWidgets();
    $id = (int) DB::table('legacy_widgets')->where('name', 'Cog')->value('id');

    // GET still works.
    $this->getJson('/api/pb/widgets')
        ->assertOk()
        ->assertJsonPath('total', 2);

    $this->getJson("/api/pb/widgets/{$id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Cog');

    // Every write verb is rejected by the read-only guard.
    $this->postJson('/api/pb/widgets', ['name' => 'New', 'qty' => 1])
        ->assertStatus(403)
        ->assertJsonPath('message', 'This collection is read-only.');

    $this->putJson("/api/pb/widgets/{$id}", ['qty' => 99])
        ->assertStatus(403)
        ->assertJsonPath('message', 'This collection is read-only.');

    $this->patchJson("/api/pb/widgets/{$id}", ['qty' => 99])
        ->assertStatus(403)
        ->assertJsonPath('message', 'This collection is read-only.');

    $this->deleteJson("/api/pb/widgets/{$id}")
        ->assertStatus(403)
        ->assertJsonPath('message', 'This collection is read-only.');

    // The underlying table was never mutated.
    expect(DB::table('legacy_widgets')->count())->toBe(2)
        ->and((int) DB::table('legacy_widgets')->where('id', $id)->value('qty'))->toBe(12);
});

// ---------------------------------------------------------------------------
// 5. A MANAGED collection can also be read-only (flag independent of external)
// ---------------------------------------------------------------------------

it('blocks writes to a managed read-only collection but allows reads', function (): void {
    $model = PbModel::create([
        'key' => 'reports',
        'name' => 'Reports',
        'table_name' => PbModel::physicalTableName('reports'),
        'is_read_only' => true,
        'has_timestamps' => false,
    ]);
    $model->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'string', 'sort' => 0]);
    app(SchemaSynchronizer::class)->sync($model->fresh());

    expect($model->isExternal())->toBeFalse()
        ->and(Schema::hasTable(PbModel::physicalTableName('reports')))->toBeTrue();

    // Seed a row directly so reads have something to return.
    $rec = app(RecordQuery::class)->create($model->fresh(), ['title' => 'Q2']);

    $this->getJson('/api/pb/reports')
        ->assertOk()
        ->assertJsonPath('total', 1);

    $this->postJson('/api/pb/reports', ['title' => 'Q3'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'This collection is read-only.');

    $this->putJson("/api/pb/reports/{$rec->id}", ['title' => 'X'])
        ->assertStatus(403);

    $this->deleteJson("/api/pb/reports/{$rec->id}")
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// 6. Managed default collection is unaffected (regression)
// ---------------------------------------------------------------------------

it('keeps managing a normal collection: creates its table and accepts writes', function (): void {
    $model = PbModel::create([
        'key' => 'notes',
        'name' => 'Notes',
        'table_name' => PbModel::physicalTableName('notes'),
        'has_timestamps' => false,
    ]);
    $model->fields()->create(['key' => 'body', 'label' => 'Body', 'type' => 'string', 'sort' => 0]);
    app(SchemaSynchronizer::class)->sync($model->fresh());

    expect($model->isExternal())->toBeFalse()
        ->and($model->isReadOnly())->toBeFalse()
        ->and(Schema::hasTable(PbModel::physicalTableName('notes')))->toBeTrue();

    $this->postJson('/api/pb/notes', ['body' => 'hello'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'hello');
});
