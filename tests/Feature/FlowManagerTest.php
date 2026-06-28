<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowRun;

it('runs a flow and records a telemetry row', function (): void {
    $flow = Flow::create([
        'slug' => 'welcome',
        'name' => 'Welcome',
        'trigger_type' => 'component',
        'is_active' => true,
        'definition' => [
            'start' => 'n1',
            'nodes' => [
                'n1' => ['type' => 'trigger', 'next' => ['n2']],
                'n2' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'Hi {{input.name}}']]]],
            ],
        ],
    ]);

    $ctx = app(FlowManager::class)->run($flow, ['name' => 'Sam']);

    expect($ctx->actions[0])->toMatchArray(['type' => 'notify', 'message' => 'Hi Sam']);

    $run = FlowRun::first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('ok')
        ->and($run->result['actions'][0]['message'])->toBe('Hi Sam')
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0);
});
