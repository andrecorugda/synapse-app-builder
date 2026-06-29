<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\FlowRun;
use Andre\AiPageBuilder\Models\Schedule;
use Andre\AiPageBuilder\Services\ScheduleRunner;
use Illuminate\Support\Carbon;

// The base TestCase migrates the core package tables; the schedules table ships
// after it, so bring it up explicitly here (mirrors how TestCase loads the rest).
beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/2026_06_29_120500_create_schedules_table.php')->up();
});

it('runs a due flow schedule and stamps last_run_at / last_status', function (): void {
    Flow::create([
        'slug' => 'nightly',
        'name' => 'Nightly',
        'trigger_type' => 'cron',
        'is_active' => true,
        'definition' => [
            'start' => 'n1',
            'nodes' => [
                'n1' => ['type' => 'trigger', 'next' => ['n2']],
                'n2' => ['type' => 'result', 'config' => ['actions' => []]],
            ],
        ],
    ]);

    $schedule = Schedule::create([
        'name' => 'Nightly job',
        'cron_expression' => '* * * * *', // due every minute
        'target_type' => 'flow',
        'target_key' => 'nightly',
        'is_active' => true,
    ]);

    $summary = app(ScheduleRunner::class)->runDue(Carbon::now());

    expect($summary)->toHaveCount(1)
        ->and($summary[0]['status'])->toBe('ok');

    // The flow ran (telemetry row written) and the schedule was stamped.
    expect(FlowRun::where('flow_slug_snapshot', 'nightly')->count())->toBe(1);

    $schedule->refresh();
    expect($schedule->last_status)->toBe('ok')
        ->and($schedule->last_run_at)->not->toBeNull()
        ->and($schedule->last_error)->toBeNull();
});

it('runs a due function schedule via the function runtime', function (): void {
    $ran = [];

    /** @var FunctionRegistry $registry */
    $registry = app(FunctionRegistry::class);
    $registry->register('record-call', function (array $args) use (&$ran): string {
        $ran[] = $args;

        return 'done';
    });

    FlowFunction::create([
        'slug' => 'ping',
        'name' => 'Ping',
        'runtime' => 'callable',
        'body' => 'record-call',
    ]);

    Schedule::create([
        'name' => 'Ping job',
        'cron_expression' => '* * * * *',
        'target_type' => 'function',
        'target_key' => 'ping',
        'args' => ['x' => 1],
        'is_active' => true,
    ]);

    $summary = app(ScheduleRunner::class)->runDue(Carbon::now());

    expect($summary)->toHaveCount(1)
        ->and($summary[0]['status'])->toBe('ok')
        ->and($ran)->toHaveCount(1)
        ->and($ran[0])->toBe(['x' => 1]);
});

it('does not run an inactive schedule', function (): void {
    Flow::create([
        'slug' => 'idle',
        'name' => 'Idle',
        'trigger_type' => 'cron',
        'is_active' => true,
        'definition' => ['start' => 'n1', 'nodes' => ['n1' => ['type' => 'trigger', 'next' => []]]],
    ]);

    $schedule = Schedule::create([
        'name' => 'Disabled job',
        'cron_expression' => '* * * * *', // would be due if active
        'target_type' => 'flow',
        'target_key' => 'idle',
        'is_active' => false,
    ]);

    $summary = app(ScheduleRunner::class)->runDue(Carbon::now());

    expect($summary)->toBe([]);
    expect(FlowRun::count())->toBe(0);

    $schedule->refresh();
    expect($schedule->last_run_at)->toBeNull();
});

it('does not run a schedule whose cron is not due now', function (): void {
    Flow::create([
        'slug' => 'sometimes',
        'name' => 'Sometimes',
        'trigger_type' => 'cron',
        'is_active' => true,
        'definition' => ['start' => 'n1', 'nodes' => ['n1' => ['type' => 'trigger', 'next' => []]]],
    ]);

    // Pin "now" to 10:30 and use a cron that only fires at 09:00 — not due.
    $now = Carbon::create(2026, 6, 29, 10, 30, 0, 'UTC');

    $schedule = Schedule::create([
        'name' => 'Morning job',
        'cron_expression' => '0 9 * * *',
        'target_type' => 'flow',
        'target_key' => 'sometimes',
        'is_active' => true,
    ]);

    $summary = app(ScheduleRunner::class)->runDue($now);

    expect($summary)->toBe([]);
    expect(FlowRun::count())->toBe(0);

    $schedule->refresh();
    expect($schedule->last_run_at)->toBeNull();
});
