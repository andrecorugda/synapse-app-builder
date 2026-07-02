<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Flow;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a Flow with a trigger → result(notify) definition.
 *
 * @param  array<string,mixed>  $overrides
 */
function makePublicFlow(array $overrides = []): Flow
{
    return Flow::create(array_merge([
        'slug' => 'test-flow-'.uniqid(),
        'name' => 'Test Flow',
        'trigger_type' => 'component',
        'is_active' => true,
        'is_public' => true,
        'definition' => [
            'start' => 'n1',
            'nodes' => [
                'n1' => ['type' => 'trigger', 'next' => ['n2']],
                'n2' => [
                    'type' => 'result',
                    'config' => [
                        'actions' => [
                            ['type' => 'notify', 'message' => 'Hi {{input.name}}'],
                        ],
                    ],
                ],
            ],
        ],
    ], $overrides));
}

// ---------------------------------------------------------------------------
// (a) Public + active flow returns 200 with interpolated actions
// ---------------------------------------------------------------------------

it('returns 200 and interpolated actions for a public active flow', function (): void {
    $flow = makePublicFlow(['slug' => 'greet-flow']);

    $response = $this->postJson('/pb-flow/greet-flow', ['input' => ['name' => 'Sam']]);

    $response->assertOk()
        ->assertJsonStructure(['actions'])
        ->assertJsonPath('actions.0.type', 'notify')
        ->assertJsonPath('actions.0.message', 'Hi Sam');
});

// ---------------------------------------------------------------------------
// (b) Access model: Public = any origin; Private = same-origin only (cross-origin 404)
// ---------------------------------------------------------------------------

it('runs a private flow from a same-origin request (trigger type does not gate access)', function (): void {
    // Access is gated by Public vs Private, NOT by trigger_type: a private flow is
    // callable from the app's own pages (same-origin). Even a "manual" flow runs
    // when the page fetches it same-origin. Laravel's test client sends Origin = host.
    makePublicFlow([
        'slug' => 'private-manual-flow',
        'is_public' => false,
        'trigger_type' => 'manual',
    ]);

    $this->postJson('/pb-flow/private-manual-flow', ['input' => ['name' => 'Sam']])
        ->assertOk()
        ->assertJsonStructure(['actions']);
});

it('returns 404 for a private flow from a cross-origin request (no existence leak)', function (): void {
    makePublicFlow([
        'slug' => 'private-xo-flow',
        'is_public' => false,
        'trigger_type' => 'manual',
    ]);

    $this->postJson('/pb-flow/private-xo-flow', ['input' => []], ['Origin' => 'https://evil.example'])
        ->assertNotFound();
});

it('returns 404 for a flow with is_active false', function (): void {
    makePublicFlow([
        'slug' => 'inactive-flow',
        'is_active' => false,
    ]);

    $this->postJson('/pb-flow/inactive-flow', ['input' => []])
        ->assertNotFound();
});

it('returns 404 for a slug that does not exist', function (): void {
    $this->postJson('/pb-flow/no-such-flow', ['input' => []])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// (c) Rate limiting: second request is 429 when rate_limit_per_minute = 1
// ---------------------------------------------------------------------------

it('returns 429 on the second request when rate_limit_per_minute is 1', function (): void {
    $slug = 'rate-limited-flow';

    makePublicFlow([
        'slug' => $slug,
        'rate_limit_per_minute' => 1,
    ]);

    // First request should succeed.
    $this->postJson('/pb-flow/'.$slug, ['input' => ['name' => 'Sam']])
        ->assertOk();

    // Second request from the same IP should be rate-limited.
    $this->postJson('/pb-flow/'.$slug, ['input' => ['name' => 'Sam']])
        ->assertStatus(429)
        ->assertJsonPath('error', 'Too many requests');
});

// ---------------------------------------------------------------------------
// (d) set_variable emits a client setState action (page store updates live)
// ---------------------------------------------------------------------------

it('a set_variable node returns a setState action so a page updates its store live', function (): void {
    Flow::create([
        'slug' => 'set-state-flow',
        'name' => 'Set State',
        'trigger_type' => 'manual',
        'is_active' => true,
        'is_public' => true,
        'definition' => [
            'start' => 'n1',
            'nodes' => [
                'n1' => ['type' => 'trigger', 'next' => ['n2']],
                'n2' => ['type' => 'set_variable', 'config' => ['key' => 'message', 'value' => 'hello', 'type' => 'string'], 'next' => []],
            ],
        ],
    ]);

    $this->postJson('/pb-flow/set-state-flow', ['input' => []])
        ->assertOk()
        ->assertJsonFragment(['type' => 'setState', 'key' => 'message', 'value' => 'hello']);
});
