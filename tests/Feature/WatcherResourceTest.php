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

it('round-trips criteria through denormalize → normalize', function (): void {
    $stored = ['config' => ['criteria' => ['status' => ['eq' => 'won']]]];

    $rows = WatcherResource::denormalizeConfig($stored);
    expect($rows['config']['criteria'])->toBe([
        ['field' => 'status', 'op' => 'eq', 'value' => 'won'],
    ]);

    $back = WatcherResource::normalizeConfig($rows);
    expect($back['config']['criteria'])->toBe($stored['config']['criteria']);
});
