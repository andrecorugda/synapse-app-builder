<?php

declare(strict_types=1);

/*
 * Regression tests for private-flow access in 2.0 (GHSA-hj3q-8gc4-6mpw): the
 * Origin header is CSRF protection only; a private flow needs a signed-in app
 * user whose role may `run` it.
 */

use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;

function flowNamed(string $slug, bool $public): Flow
{
    return Flow::create([
        'slug' => $slug, 'name' => $slug, 'trigger_type' => 'component', 'is_active' => true, 'is_public' => $public,
        'definition' => ['start' => 't', 'nodes' => [
            't' => ['type' => 'trigger', 'next' => ['r']],
            'r' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'ran']]]],
        ]],
    ]);
}

function appUser(string $roleSlug = 'staff', bool $admin = false): PbUser
{
    $role = PbRole::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug), 'is_admin' => $admin]);

    return PbUser::create(['name' => $roleSlug, 'email' => $roleSlug.uniqid().'@x.test', 'password' => 'x', 'role_id' => $role->id]);
}

it('does not run a private flow for an anonymous caller that forges Origin', function (): void {
    flowNamed('internal-op', public: false);

    // curl -H 'Origin: http://<app-host>' — trivially forged outside a browser.
    $this->withHeader('Origin', 'http://localhost')->postJson('/pb-flow/internal-op', ['input' => []])->assertNotFound();
});

it('runs a private flow for any signed-in user when no role has a run permission for it', function (): void {
    flowNamed('internal-op', public: false);

    $this->actingAs(appUser(), 'pb')->postJson('/pb-flow/internal-op', ['input' => []])
        ->assertOk()->assertJsonPath('actions.0.message', 'ran');
});

it('runs a restricted private flow only for a role granted run (and admins)', function (): void {
    flowNamed('payroll-run', public: false);
    $finance = appUser('finance');
    PbPermission::create(['role_id' => $finance->role_id, 'resource_type' => 'flow', 'resource_key' => 'payroll-run', 'action' => 'run']);

    $this->actingAs(appUser('staff'), 'pb')->postJson('/pb-flow/payroll-run', ['input' => []])->assertNotFound();
    $this->actingAs($finance, 'pb')->postJson('/pb-flow/payroll-run', ['input' => []])->assertOk();
    $this->actingAs(appUser('admin', admin: true), 'pb')->postJson('/pb-flow/payroll-run', ['input' => []])->assertOk();
});

it('refuses a signed-in user\'s private flow call from another origin (CSRF)', function (): void {
    flowNamed('internal-op', public: false);

    $this->actingAs(appUser(), 'pb')
        ->postJson('/pb-flow/internal-op', ['input' => []], ['Origin' => 'https://evil.example'])
        ->assertNotFound();
});

it('keeps private flows unreachable over HTTP when app auth is off', function (): void {
    config()->set('ai-page-builder.auth.enabled', false);
    flowNamed('internal-op', public: false);

    $this->postJson('/pb-flow/internal-op', ['input' => []])->assertNotFound();
});

it('keeps public flows open to anyone, from any origin', function (): void {
    flowNamed('contact', public: true);

    $this->postJson('/pb-flow/contact', ['input' => []], ['Origin' => 'https://elsewhere.example'])->assertOk();
});
