<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Seeders\SystemPagesSeeder;
use Andre\AiPageBuilder\Services\Settings;

it('seeds home, not-found and maintenance pages and points settings at them', function (): void {
    app(SystemPagesSeeder::class)->run();

    expect(Page::query()->where('slug', 'home')->exists())->toBeTrue();
    expect(Page::query()->where('slug', 'not-found')->exists())->toBeTrue();
    expect(Page::query()->where('slug', 'maintenance')->exists())->toBeTrue();

    $settings = app(Settings::class);
    expect($settings->get('home_page'))->toBe('home');
    expect($settings->get('not_found_page'))->toBe('not-found');
    expect($settings->get('maintenance_page'))->toBe('maintenance');
});

it('never overrides a setting the host already chose', function (): void {
    app(Settings::class)->set('home_page', 'my-landing');
    app(SystemPagesSeeder::class)->run();

    expect(app(Settings::class)->get('home_page'))->toBe('my-landing');
});

it('is idempotent — re-running does not duplicate or clobber', function (): void {
    app(SystemPagesSeeder::class)->run();
    app(SystemPagesSeeder::class)->run();

    expect(Page::query()->where('slug', 'home')->count())->toBe(1);
});

it('renders the 404 page with HTTP 404 for an unknown slug', function (): void {
    app(SystemPagesSeeder::class)->run();

    $this->get('/p/does-not-exist')
        ->assertStatus(404)
        ->assertSee('Page not found');
});

it('shows the maintenance page (503) for an existing page that was unpublished', function (): void {
    app(SystemPagesSeeder::class)->run();
    Page::factory()->create(['slug' => 'about', 'status' => 'draft', 'kind' => 'page']);

    $this->get('/p/about')
        ->assertStatus(503)
        ->assertSee('right back');
});

it('serves the seeded home page at the render root', function (): void {
    app(SystemPagesSeeder::class)->run();

    $this->get('/p')
        ->assertOk()
        ->assertSee('Welcome to your new site');
});

it('returns a 503 maintenance page to visitors when maintenance mode is on', function (): void {
    app(SystemPagesSeeder::class)->run();
    app(Settings::class)->set('maintenance_mode', true);

    $this->get('/p/home')
        ->assertStatus(503)
        ->assertSee('right back');
});
