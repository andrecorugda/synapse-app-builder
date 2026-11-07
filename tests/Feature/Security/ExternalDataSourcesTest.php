<?php

declare(strict_types=1);

/*
 * External collections cannot be turned into a write path into the host's
 * own tables (adversarial review A2).
 *
 * Mapping an existing table needs `synapse.manage-data-sources` AND a
 * connection/table the host listed in `ai-page-builder.data.external`.
 * Synapse's own tables and the host's auth tables are refused at the data
 * layer for every path, and external tables are read-only unless the host
 * enables `data.external.writable`.
 */

use Andre\AiPageBuilder\Exceptions\DataSourceRefusedException;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource\Pages\CreatePbModel;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource\Pages\EditPbModel;
use Andre\AiPageBuilder\Models\PbApiToken;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Andre\AiPageBuilder\Support\DataSourceGuard;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Andre\AiPageBuilder\Tests\Fixtures\PanelStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function (): void {
    bootSynapsePanel();
    // The host's panel users are its auth users.
    config()->set('auth.providers.users.model', PanelStaff::class);

    Schema::create('legacy_orders', function ($t): void {
        $t->id();
        $t->string('ref');
    });
    DB::table('legacy_orders')->insert([['ref' => 'A-1'], ['ref' => 'A-2']]);
});

/** Open the `testing` connection and the given tables to external collections. */
function openExternal(array $tables = ['legacy_orders']): void
{
    config()->set('ai-page-builder.data.external.connections', ['testing']);
    config()->set('ai-page-builder.data.external.tables', $tables);
}

/** Map an external collection straight through the model (as an import would). */
function externalCollection(string $table, array $overrides = []): PbModel
{
    return PbModel::query()->create($overrides + [
        'key' => 'ext_'.uniqid(),
        'name' => 'External',
        'table_name' => $table,
        'source_type' => PbModel::SOURCE_EXTERNAL,
        'source_connection' => 'testing',
        'is_read_only' => false,
        'has_timestamps' => false,
    ]);
}

function externalForm(string $table): array
{
    return [
        'key' => 'staff', 'name' => 'Staff',
        'source_type' => PbModel::SOURCE_EXTERNAL,
        'source_connection' => 'testing',
        'table_name' => $table,
        'is_read_only' => false,
    ];
}

it('refuses an external collection to a builder without synapse.manage-data-sources', function (): void {
    openExternal(['*']);
    Gate::define('synapse.manage-data-sources', fn (): bool => false);
    $this->actingAs(synapseAdmin());

    Livewire::test(CreatePbModel::class)
        ->fillForm(externalForm('legacy_orders'))
        ->call('create')
        ->assertHasFormErrors(['source_type']);

    expect(PbModel::query()->where('key', 'staff')->exists())->toBeFalse();
});

it('refuses external collections entirely until the host lists a connection and table', function (): void {
    $this->actingAs(synapseAdmin());

    Livewire::test(CreatePbModel::class)
        ->fillForm(externalForm('legacy_orders'))
        ->call('create')
        ->assertHasFormErrors(['table_name']);

    expect(fn () => externalCollection('legacy_orders'))->toThrow(DataSourceRefusedException::class);

    // A listed table on a listed connection is fine.
    openExternal();
    Livewire::test(CreatePbModel::class)
        ->fillForm(externalForm('legacy_orders'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PbModel::query()->where('key', 'staff')->value('table_name'))->toBe('legacy_orders');
});

it('refuses a table listed only for another connection', function (): void {
    config()->set('ai-page-builder.data.external.connections', ['testing']);
    config()->set('ai-page-builder.data.external.tables', ['reporting' => ['legacy_orders']]);

    expect(DataSourceGuard::externalRefusal('testing', 'legacy_orders'))->not->toBeNull();

    config()->set('ai-page-builder.data.external.tables', ['testing' => ['legacy_orders']]);
    expect(DataSourceGuard::externalRefusal('testing', 'legacy_orders'))->toBeNull();
});

it('always refuses the host auth tables and Synapse tables, even with every table listed', function (string $table): void {
    openExternal(['*']);
    $this->actingAs(synapseAdmin());

    Livewire::test(CreatePbModel::class)
        ->fillForm(externalForm($table))
        ->call('create')
        ->assertHasFormErrors(['table_name']);

    expect(fn () => externalCollection($table))->toThrow(DataSourceRefusedException::class);
})->with([
    'host users model table' => 'panel_staff',
    'host users (quoted, schema-qualified)' => 'main.PANEL_STAFF',
    'password reset tokens' => 'password_reset_tokens',
    'sessions' => 'sessions',
    'personal access tokens' => 'personal_access_tokens',
    'Synapse app users' => 'page_builder_users',
    'Synapse roles' => 'page_builder_roles',
    'Synapse api tokens' => 'page_builder_api_tokens',
    'Synapse credentials' => 'credentials',
    'Synapse collections' => 'page_builder_models',
    'Synapse settings' => 'page_builder_settings',
]);

it('refuses tables the host adds to protected_tables and the configured permission tables', function (): void {
    openExternal(['*']);
    config()->set('ai-page-builder.data.external.protected_tables', ['legacy_orders']);
    config()->set('permission.table_names', ['roles' => 'acl_roles']);

    expect(DataSourceGuard::isProtectedTable('legacy_orders', 'testing'))->toBeTrue()
        ->and(DataSourceGuard::isProtectedTable('acl_roles', 'testing'))->toBeTrue()
        ->and(DataSourceGuard::isProtectedTable(PbApiToken::tableName(), 'testing'))->toBeTrue();
});

it('cannot reach the host users table through a tampered collection row, a flow or the data API', function (): void {
    openExternal(['*']);
    config()->set('ai-page-builder.data.external.writable', true);
    $me = synapseAdmin(['email' => 'mallory@corp.test']);
    $this->actingAs($me);

    // A collection row written around PbModel (raw SQL / an old import).
    $id = DB::table(PbSchema::table('models'))->insertGetId([
        'key' => 'staff', 'name' => 'Staff', 'table_name' => 'panel_staff',
        'source_type' => PbModel::SOURCE_EXTERNAL, 'source_connection' => 'testing',
        'is_read_only' => false, 'has_timestamps' => false, 'has_soft_deletes' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    PbField::query()->create(['model_id' => $id, 'key' => 'email', 'label' => 'Email', 'type' => 'string', 'sort' => 1]);

    expect(fn () => Record::for('staff'))->toThrow(DataSourceRefusedException::class);

    // A public flow that updates my own host-user row.
    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => ['flows' => [[
        'slug' => 'promote', 'name' => 'promote', 'trigger_type' => 'api', 'is_public' => true,
        'definition' => ['start' => 't', 'nodes' => [
            't' => ['type' => 'trigger', 'next' => ['n']],
            'n' => ['type' => 'record', 'config' => [
                'model' => 'staff', 'operation' => 'update', 'id' => $me->id,
                'data' => ['email' => 'mallory@owner.test'], 'output' => 'r',
            ]],
        ]],
    ]]]])->assertOk();
    $this->postJson('/pb-flow/promote', ['input' => []]);

    // Straight at the data API too.
    $this->patchJson('/api/pb/staff/'.$me->id, ['email' => 'mallory@owner.test'])->assertForbidden();

    expect($me->fresh()->email)->toBe('mallory@corp.test');
});

it('never lets a managed collection take over a protected table when the prefix is empty', function (string $key): void {
    config()->set('ai-page-builder.data.table_prefix', '');

    expect(fn () => PbModel::query()->create([
        'key' => $key, 'name' => $key, 'table_name' => PbModel::physicalTableName($key), 'has_timestamps' => false,
    ]))->toThrow(DataSourceRefusedException::class);

    // A tampered row is still never synced (altered) or dropped.
    $model = new PbModel(['key' => $key, 'table_name' => $key, 'source_type' => PbModel::SOURCE_MANAGED]);
    expect(fn () => app(SchemaSynchronizer::class)->sync($model))->toThrow(DataSourceRefusedException::class);
    app(SchemaSynchronizer::class)->dropTable($model);
    expect(Schema::hasTable($key))->toBeTrue();
})->with(['panel_staff', 'pages']);

it('keeps external collections read-only unless the host enables writes', function (): void {
    openExternal();
    $orders = externalCollection('legacy_orders');
    $orders->fields()->create(['key' => 'ref', 'label' => 'Ref', 'type' => 'string', 'sort' => 0]);
    $orders = $orders->fresh();
    $records = app(RecordQuery::class);
    $firstId = (int) DB::table('legacy_orders')->min('id');

    // Read-only toggle off, but the host switch is off: still read-only, everywhere.
    expect($orders->isReadOnly())->toBeTrue()
        ->and($orders->externalWritesBlocked())->toBeTrue()
        ->and($records->list($orders)->total())->toBe(2);
    expect(fn () => $records->create($orders, ['ref' => 'X']))->toThrow(DataSourceRefusedException::class);
    expect(fn () => $records->update($orders, $firstId, ['ref' => 'X']))->toThrow(DataSourceRefusedException::class);
    expect(fn () => $records->delete($orders, $firstId))->toThrow(DataSourceRefusedException::class);
    $this->postJson('/api/pb/'.$orders->key, ['ref' => 'X'])->assertForbidden();
    expect(DB::table('legacy_orders')->count())->toBe(2);

    // Host opts in: the collection's own toggle now decides.
    config()->set('ai-page-builder.data.external.writable', true);
    $orders = $orders->fresh();
    expect($orders->isReadOnly())->toBeFalse();
    $records->create($orders, ['ref' => 'A-3']);
    expect(DB::table('legacy_orders')->count())->toBe(3);

    $orders->update(['is_read_only' => true]);
    expect(fn () => $records->create($orders->fresh(), ['ref' => 'A-4']))->toThrow(DataSourceRefusedException::class);
});

it('does not let a builder without manage-data-sources repoint an existing external collection', function (): void {
    openExternal(['*']);
    Schema::create('legacy_other', function ($t): void {
        $t->id();
    });
    $orders = externalCollection('legacy_orders', ['key' => 'orders']);

    Gate::define('synapse.manage-data-sources', fn (): bool => false);
    $this->actingAs(synapseAdmin());

    // The source inputs are disabled, so the change is not saved …
    Livewire::test(EditPbModel::class, ['record' => $orders->key])
        ->fillForm(['name' => 'Orders!', 'table_name' => 'legacy_other', 'is_read_only' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $orders->fresh();
    expect($fresh->table_name)->toBe('legacy_orders')
        ->and($fresh->name)->toBe('Orders!');
});
