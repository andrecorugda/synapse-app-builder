<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Exceptions\DataSourceRefusedException;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Support\DataSourceGuard;

it('refuses the host tables whose rows are unserialized (queue jobs, failed jobs, cache)', function (string $table): void {
    config()->set('database.connections.testing.prefix', '');
    // The default `cache.stores.database.table` / `queue.connections.database.table` names.
    expect(DataSourceGuard::isProtectedTable($table, 'testing'))->toBeTrue("{$table} is mappable");
})->with(['jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks']);

it('refuses a managed collection whose key lands on the queue table when data.table_prefix is empty', function (): void {
    config()->set('ai-page-builder.data.table_prefix', '');
    expect(fn () => PbModel::query()->create([
        'key' => 'jobs', 'name' => 'Jobs', 'table_name' => PbModel::physicalTableName('jobs'),
    ]))->toThrow(DataSourceRefusedException::class);
});

it('does not let a * table allow-list reach other databases or schemas', function (string $table): void {
    config()->set('ai-page-builder.data.external.connections', ['testing']);
    config()->set('ai-page-builder.data.external.tables', ['*']);

    expect(DataSourceGuard::externalRefusal('testing', $table))->not->toBeNull();
})->with(['mysql.user', 'information_schema.tables']);
