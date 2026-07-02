<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Filament\Resources\WatcherResource;

it('normalizes criteria rows into the { field: { op: value } } map', function (): void {
    $data = WatcherResource::normalizeConfig([
        'config' => [
            'criteria' => [
                ['field' => 'status', 'op' => 'eq', 'value' => 'won'],
                ['field' => 'score', 'op' => 'gte', 'value' => '50'],
            ],
        ],
    ]);

    expect($data['config']['criteria'])->toBe([
        'status' => ['eq' => 'won'],
        'score' => ['gte' => '50'],
    ]);
});

it('drops empty criteria and skips rows without a field', function (): void {
    $data = WatcherResource::normalizeConfig([
        'config' => ['criteria' => [['field' => '', 'op' => 'eq', 'value' => 'x']]],
    ]);

    expect($data['config'])->not->toHaveKey('criteria');
});

it('normalizes a state watcher: forces event=changed and strips empty conditions', function (): void {
    $data = WatcherResource::normalizeConfig([
        'source_type' => 'state',
        'event' => 'created',
        'config' => ['path' => 'address.city', 'from' => '', 'to' => 'LA', 'op' => '', 'value' => null],
    ]);

    expect($data['event'])->toBe('changed')
        ->and($data['config'])->toBe(['path' => 'address.city', 'to' => 'LA']);
});

it('nulls a state watcher config when no conditions are set', function (): void {
    $data = WatcherResource::normalizeConfig([
        'source_type' => 'state',
        'config' => ['path' => '', 'from' => '', 'to' => '', 'op' => '', 'value' => ''],
    ]);

    expect($data['config'])->toBeNull();
});

it('leaves a state watcher config untouched on denormalize', function (): void {
    $stored = ['source_type' => 'state', 'config' => ['path' => 'a.b', 'to' => 'x']];

    expect(WatcherResource::denormalizeConfig($stored))->toBe($stored);
});

it('round-trips criteria through denormalize → normalize', function (): void {
    $stored = ['config' => ['criteria' => ['status' => ['eq' => 'won']]]];

    $rows = WatcherResource::denormalizeConfig($stored);
    expect($rows['config']['criteria'])->toBe([
        ['field' => 'status', 'op' => 'eq', 'value' => 'won'],
    ]);

    $back = WatcherResource::normalizeConfig($rows);
    expect($back['config']['criteria'])->toBe($stored['config']['criteria']);
});
