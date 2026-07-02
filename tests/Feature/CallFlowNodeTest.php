<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Flow\NodeRegistry;
use Andre\AiPageBuilder\Models\Flow;
use Illuminate\Support\Facades\Log;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a Flow whose definition has a single set_variable node that writes
 * $value into vars.$varName via the `output` field (not the global store).
 */
function makeChildFlowWithOutput(string $slug, string $varName, mixed $value): Flow
{
    return Flow::create([
        'slug' => $slug,
        'name' => $slug,
        'trigger_type' => 'component',
        'is_active' => true,
        'definition' => [
            'start' => 'set',
            'nodes' => [
                'set' => [
                    'type' => 'set_variable',
                    'config' => [
                        'key' => $varName,
                        'value' => $value,
                        'output' => $varName,
                    ],
                ],
            ],
        ],
    ]);
}

// ---------------------------------------------------------------------------
// Shared context — sub-flow output visible to parent
// ---------------------------------------------------------------------------

it('parent sees a var set by the child flow (shared context)', function (): void {
    makeChildFlowWithOutput('child-greeting', 'greeting', 'hello from child');

    $runner = app(FlowRunner::class);

    $definition = [
        'start' => 'call',
        'nodes' => [
            'call' => [
                'type' => 'call_flow',
                'config' => ['flow' => 'child-greeting'],
                'next' => [],
            ],
        ],
    ];

    $ctx = $runner->run($definition);

    expect($ctx->vars['greeting'])->toBe('hello from child')
        ->and($ctx->failed)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Shared context — child reads a var the parent set before the call
// ---------------------------------------------------------------------------

it('child flow reads a var the parent set before the call', function (): void {
    // Child flow reads vars.parent_msg and writes it to vars.echo_msg (via output).
    Flow::create([
        'slug' => 'child-reader',
        'name' => 'child-reader',
        'trigger_type' => 'component',
        'is_active' => true,
        'definition' => [
            'start' => 'set',
            'nodes' => [
                'set' => [
                    'type' => 'set_variable',
                    'config' => [
                        'key' => 'echo_msg',
                        'value' => '{{ vars.parent_msg }}',
                        'output' => 'echo_msg',
                    ],
                ],
            ],
        ],
    ]);

    $runner = app(FlowRunner::class);

    // Parent writes vars.parent_msg then calls the child.
    $definition = [
        'start' => 'set_parent',
        'nodes' => [
            'set_parent' => [
                'type' => 'set_variable',
                'config' => [
                    'key' => 'parent_msg',
                    'value' => 'written by parent',
                    'output' => 'parent_msg',
                ],
                'next' => ['call'],
            ],
            'call' => [
                'type' => 'call_flow',
                'config' => ['flow' => 'child-reader'],
                'next' => [],
            ],
        ],
    ];

    $ctx = $runner->run($definition);

    expect($ctx->vars['echo_msg'])->toBe('written by parent')
        ->and($ctx->failed)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Output variable — snapshot stored under the configured key
// ---------------------------------------------------------------------------

it('stores a vars snapshot in the output variable when configured', function (): void {
    makeChildFlowWithOutput('child-snapshot', 'snap_val', 42);

    $runner = app(FlowRunner::class);

    $definition = [
        'start' => 'call',
        'nodes' => [
            'call' => [
                'type' => 'call_flow',
                'config' => ['flow' => 'child-snapshot', 'output' => 'sub_result'],
                'next' => [],
            ],
        ],
    ];

    $ctx = $runner->run($definition);

    // The output var receives the vars snapshot, which includes snap_val.
    expect($ctx->vars['sub_result'])->toBeArray()
        ->and($ctx->vars['sub_result']['snap_val'])->toBe(42);
});

// ---------------------------------------------------------------------------
// Cycle guard — self-reference is blocked
// ---------------------------------------------------------------------------

it('blocks a flow that directly calls itself (self-reference)', function (): void {
    Log::spy();

    $handler = app(NodeRegistry::class)->get('call_flow');

    // Simulate: we are already executing 'self-loop' (it is on the call stack).
    $context = new FlowContext;
    $context->callStack = ['self-loop'];

    $node = [
        'type' => 'call_flow',
        'config' => ['flow' => 'self-loop'],
        'next' => [],
    ];

    $handler->run($node, $context);

    expect($context->failed)->toBeTrue()
        ->and($context->error)->toContain('self-loop');

    Log::assertLogged('warning', fn (string $message, array $ctx) => str_contains($message, 'call_flow cycle blocked'));
});

it('catches a top-level self-call at the first level (body does not run an extra pass)', function (): void {
    Log::spy();

    // A flow whose body appends to a global then calls ITSELF. Run through the
    // FlowManager (the real entry point), which seeds the call stack with the
    // running flow's slug. Without that seed the guard only tripped one level
    // deep, so the body ran a whole extra pass (the notify/side effect fired
    // twice). The append node must therefore run exactly once.
    Flow::create([
        'slug' => 'self-loop',
        'name' => 'self-loop',
        'trigger_type' => 'component',
        'is_active' => true,
        'definition' => [
            'start' => 'set',
            'nodes' => [
                'set' => [
                    'type' => 'set_variable',
                    'config' => ['key' => 'hits', 'value' => '{{ vars.hits }}x', 'output' => 'hits'],
                    'next' => ['again'],
                ],
                'again' => [
                    'type' => 'call_flow',
                    'config' => ['flow' => 'self-loop'],
                    'next' => [],
                ],
            ],
        ],
    ]);

    $flow = Flow::where('slug', 'self-loop')->firstOrFail();
    $ctx = app(FlowManager::class)->run($flow, ['hits' => '']);

    // The set_variable ran exactly once → 'hits' is a single "x", not "xx".
    expect($ctx->get('vars.hits'))->toBe('x');
    Log::assertLogged('warning', fn (string $message, array $ctx) => str_contains($message, 'call_flow cycle blocked'));
});

// ---------------------------------------------------------------------------
// Cycle guard — indirect cycle A→B→A is blocked
// ---------------------------------------------------------------------------

it('blocks an indirect cycle (B tries to call A while A is already running)', function (): void {
    Log::spy();

    $handler = app(NodeRegistry::class)->get('call_flow');

    // Simulate: A called B, B is now trying to call A again.
    // The call stack already contains 'flow-a'.
    $context = new FlowContext;
    $context->callStack = ['flow-a', 'flow-b'];

    $node = [
        'type' => 'call_flow',
        'config' => ['flow' => 'flow-a'],  // back-edge to A
        'next' => [],
    ];

    $handler->run($node, $context);

    expect($context->failed)->toBeTrue()
        ->and($context->error)->toContain('flow-a');

    Log::assertLogged('warning', fn (string $message, array $ctx) => str_contains($message, 'call_flow cycle blocked'));
});

// ---------------------------------------------------------------------------
// No-op when the referenced flow does not exist
// ---------------------------------------------------------------------------

it('does nothing when the referenced flow does not exist', function (): void {
    $runner = app(FlowRunner::class);

    $definition = [
        'start' => 'call',
        'nodes' => [
            'call' => [
                'type' => 'call_flow',
                'config' => ['flow' => 'does-not-exist'],
                'next' => [],
            ],
        ],
    ];

    $ctx = $runner->run($definition);

    expect($ctx->failed)->toBeFalse();
});

// ---------------------------------------------------------------------------
// No-op when the flow slug is empty
// ---------------------------------------------------------------------------

it('does nothing when the flow slug is empty', function (): void {
    $runner = app(FlowRunner::class);

    $definition = [
        'start' => 'call',
        'nodes' => [
            'call' => [
                'type' => 'call_flow',
                'config' => ['flow' => ''],
                'next' => [],
            ],
        ],
    ];

    $ctx = $runner->run($definition);

    expect($ctx->failed)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Call stack is restored after the sub-run (push/pop)
// ---------------------------------------------------------------------------

it('pops the slug off the call stack after the sub-run completes', function (): void {
    makeChildFlowWithOutput('child-pop-check', 'was_set', 'yes');

    $handler = app(NodeRegistry::class)->get('call_flow');

    $context = new FlowContext;
    $context->callStack = ['parent-flow'];

    $node = [
        'type' => 'call_flow',
        'config' => ['flow' => 'child-pop-check'],
        'next' => [],
    ];

    $handler->run($node, $context);

    // After the sub-run the call stack is restored to its pre-call state.
    expect($context->callStack)->toBe(['parent-flow'])
        ->and($context->vars['was_set'])->toBe('yes');
});

// ---------------------------------------------------------------------------
// Registry metadata
// ---------------------------------------------------------------------------

it('registers call_flow with the expected definition shape', function (): void {
    $defs = collect(app(NodeRegistry::class)->definitions())->keyBy('key');

    expect($defs->has('call_flow'))->toBeTrue();

    $def = $defs->get('call_flow');
    expect($def->label)->toBe('Run Flow (sub-flow)')
        ->and($def->outputHandles)->toContain('next');
});
