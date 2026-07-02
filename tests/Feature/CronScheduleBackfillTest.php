<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\Schedule;

function makeCronFlow(string $slug): Flow
{
    return Flow::create([
        'slug' => $slug,
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'trigger_type' => 'cron',
        'is_active' => true,
        'definition' => ['start' => 'n1', 'nodes' => ['n1' => ['type' => 'trigger', 'next' => []]]],
    ]);
}

function runCronBackfill(): void
{
    (include __DIR__.'/../../database/migrations/backfill_cron_flows_into_schedules.php')->up();
}

it('surfaces each cron flow as an inactive schedule to review', function (): void {
    makeCronFlow('nightly-digest');

    runCronBackfill();

    $schedule = Schedule::query()->where('target_key', 'nightly-digest')->first();
    expect($schedule)->not->toBeNull()
        ->and($schedule->target_type)->toBe('flow')
        ->and($schedule->is_active)->toBeFalse()
        ->and($schedule->name)->toContain('review cadence')
        ->and($schedule->cron_expression)->not->toBe('');
});

it('is idempotent and does not touch a flow that already has a schedule', function (): void {
    makeCronFlow('nightly-digest');

    runCronBackfill();
    runCronBackfill();

    expect(Schedule::query()->where('target_key', 'nightly-digest')->count())->toBe(1);
});

it('leaves non-cron flows alone', function (): void {
    Flow::create([
        'slug' => 'on-click',
        'name' => 'On click',
        'trigger_type' => 'manual',
        'is_active' => true,
        'definition' => ['start' => 'n1', 'nodes' => ['n1' => ['type' => 'trigger', 'next' => []]]],
    ]);

    runCronBackfill();

    expect(Schedule::query()->where('target_key', 'on-click')->exists())->toBeFalse();
});
