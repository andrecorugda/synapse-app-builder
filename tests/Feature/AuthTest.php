<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbUser;

function pbUser(array $attrs = []): PbUser
{
    return PbUser::query()->create(array_merge([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'secret-pass',
        'is_active' => true,
    ], $attrs));
}

it('serves a public page without authentication', function (): void {
    Page::factory()->published()->create(['slug' => 'open', 'html' => '<p>open</p>', 'requires_auth' => false]);

    $this->get('/p/open')->assertOk()->assertSee('open', false);
});

it('redirects a guest from a gated page to the login page', function (): void {
    Page::factory()->published()->create(['slug' => 'members', 'html' => '<p>secret</p>', 'requires_auth' => true]);

    $this->get('/p/members')
        ->assertRedirect('/login');
});

it('serves a gated page to a logged-in app user', function (): void {
    Page::factory()->published()->create(['slug' => 'members', 'html' => '<p>secret</p>', 'requires_auth' => true]);

    $this->actingAs(pbUser(), 'pb')
        ->get('/p/members')
        ->assertOk()
        ->assertSee('secret', false);
});

it('logs in with valid credentials', function (): void {
    pbUser();

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect();

    expect(auth('pb')->check())->toBeTrue();
});

it('rejects invalid credentials', function (): void {
    pbUser();

    $this->from('/login')
        ->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
        ->assertRedirect('/login');

    expect(auth('pb')->check())->toBeFalse();
});

it('refuses login for a deactivated user', function (): void {
    pbUser(['is_active' => false]);

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass']);

    expect(auth('pb')->check())->toBeFalse();
});

it('shows the login page', function (): void {
    $this->get('/login')->assertOk()->assertSee('Welcome back', false);
});

it('logs out', function (): void {
    $this->actingAs(pbUser(), 'pb');

    $this->post('/pb-logout')->assertRedirect('/login');

    expect(auth('pb')->check())->toBeFalse();
});
