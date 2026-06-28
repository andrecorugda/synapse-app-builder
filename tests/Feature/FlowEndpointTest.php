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
// (b) Non-public flow → 404 (existence must not leak)
// ---------------------------------------------------------------------------

it('returns 404 for a flow with is_public false', function (): void {
    makePublicFlow([
        'slug' => 'private-flow',
        'is_public' => false,
    ]);

    $this->postJson('/pb-flow/private-flow', ['input' => []])
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
