<?php

declare(strict_types=1);

/*
 * Regression tests for page `view` permissions in 2.0 (GHSA-hj3q-8gc4-6mpw): a
 * page restricted to roles is enforced on the live route, the preview route, the
 * special (404 / maintenance) pages, the render cache and the sitemap.
 */

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Support\Facades\URL;

/** A published `payroll` page only the HR role may view. */
function restrictedPayroll(): array
{
    Page::factory()->create(['slug' => 'payroll', 'status' => 'published', 'html' => '<p>TOP-SECRET-PAYROLL</p>']);
    $hr = PbRole::create(['name' => 'HR', 'slug' => 'hr', 'is_admin' => false]);
    $staff = PbRole::create(['name' => 'Staff', 'slug' => 'staff', 'is_admin' => false]);
    PbPermission::create(['role_id' => $hr->id, 'resource_type' => 'page', 'resource_key' => 'payroll', 'action' => 'view']);

    return [
        PbUser::create(['name' => 'Hana', 'email' => 'hana@x.test', 'password' => 'x', 'role_id' => $hr->id]),
        PbUser::create(['name' => 'Sam', 'email' => 'sam@x.test', 'password' => 'x', 'role_id' => $staff->id]),
    ];
}

it('sends a guest to the login page instead of serving a role-restricted page', function (): void {
    restrictedPayroll();

    $res = $this->get('/p/payroll');

    $res->assertRedirect('/login');
    expect($res->getContent())->not->toContain('TOP-SECRET-PAYROLL');
});

it('serves the 404 page to a signed-in user whose role lacks view', function (): void {
    [, $sam] = restrictedPayroll();

    $res = $this->actingAs($sam, 'pb')->get('/p/payroll');

    $res->assertNotFound();
    expect($res->getContent())->not->toContain('TOP-SECRET-PAYROLL');
});

it('serves a restricted page to a granted role and to an admin', function (): void {
    [$hana] = restrictedPayroll();
    $admin = PbUser::create([
        'name' => 'Root', 'email' => 'root@x.test', 'password' => 'x',
        'role_id' => PbRole::create(['name' => 'Admin', 'slug' => 'admin', 'is_admin' => true])->id,
    ]);

    $this->actingAs($hana, 'pb')->get('/p/payroll')->assertOk()->assertSee('TOP-SECRET-PAYROLL', false);
    $this->actingAs($admin, 'pb')->get('/p/payroll')->assertOk()->assertSee('TOP-SECRET-PAYROLL', false);
});

it('never lets the render cache serve a restricted page to someone not allowed', function (): void {
    [$hana, $sam] = restrictedPayroll();

    // Warm the cache as the granted user...
    $this->actingAs($hana, 'pb')->get('/p/payroll')->assertOk();

    // ...then a different, ungranted user asks for the same slug.
    $this->actingAs($sam, 'pb')->get('/p/payroll')->assertNotFound()->assertDontSee('TOP-SECRET-PAYROLL', false);
});

it('keeps a page with no permission rows open to everyone', function (): void {
    Page::factory()->create(['slug' => 'about', 'status' => 'published', 'html' => '<p>about-us</p>']);

    $this->get('/p/about')->assertOk()->assertSee('about-us', false);
});

it('applies the page gates to a signed preview link', function (): void {
    [$hana, $sam] = restrictedPayroll();
    $url = URL::temporarySignedRoute('ai-page-builder.preview', now()->addHour(), ['slug' => 'payroll']);

    $this->get($url)->assertRedirect('/login');
    $this->actingAs($sam, 'pb')->get($url)->assertNotFound();
    $this->actingAs($hana, 'pb')->get($url)->assertOk()->assertSee('TOP-SECRET-PAYROLL', false);
});

it('does not show a restricted page configured as the 404 page to a visitor who may not see it', function (): void {
    restrictedPayroll();
    app(Settings::class)->set('not_found_page', 'payroll');

    $res = $this->get('/p/does-not-exist');

    $res->assertNotFound();
    expect($res->getContent())->not->toContain('TOP-SECRET-PAYROLL');
});

it('leaves restricted and login-only pages out of the sitemap', function (): void {
    restrictedPayroll();
    Page::factory()->create(['slug' => 'members', 'status' => 'published', 'requires_auth' => true]);
    Page::factory()->create(['slug' => 'pricing', 'status' => 'published']);

    $xml = $this->get('/p/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('/p/pricing')->not->toContain('payroll')->not->toContain('members');
});

it('sends the configured Content-Security-Policy on rendered pages', function (): void {
    Page::factory()->create(['slug' => 'about', 'status' => 'published']);

    $this->get('/p/about')->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'");

    config()->set('ai-page-builder.render.content_security_policy', null);
    $this->get('/p/about')->assertHeaderMissing('Content-Security-Policy');
});
