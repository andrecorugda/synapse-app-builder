<?php

declare(strict_types=1);

/*
 * Page view permissions are keyed by slug: renaming or deleting a page must
 * keep them attached to the page, never leave it public or hand stale grants
 * to a later page that reuses the slug.
 */

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;

function slugPermPayroll(): array
{
    $page = Page::factory()->create(['slug' => 'payroll', 'status' => 'published', 'html' => '<p>TOP-SECRET-PAYROLL</p>']);
    $hr = PbRole::create(['name' => 'HR', 'slug' => 'hr-'.uniqid(), 'is_admin' => false]);
    PbPermission::create(['role_id' => $hr->id, 'resource_type' => 'page', 'resource_key' => 'payroll', 'action' => 'view']);

    return [$page, $hr];
}

it('keeps a restricted page restricted after its slug is renamed', function (): void {
    [$page, $hr] = slugPermPayroll();
    $this->get('/p/payroll')->assertRedirect('/login');

    $page->update(['slug' => 'payroll-2026']);

    $res = $this->get('/p/payroll-2026');
    expect($res->getContent())->not->toContain('TOP-SECRET-PAYROLL');
    $res->assertRedirect('/login');

    // The permitted role still opens it under the new address.
    $member = PbUser::create(['name' => 'H', 'email' => 'h'.uniqid().'@x.test', 'password' => 'x', 'role_id' => $hr->id]);
    $this->actingAs($member, 'pb')->get('/p/payroll-2026')->assertOk()->assertSee('TOP-SECRET-PAYROLL', false);

    expect(PbPermission::query()->where('resource_type', 'page')->where('resource_key', 'payroll')->exists())->toBeFalse();
});

it('does not serve the restricted page from a cache warmed before the rename', function (): void {
    [$page] = slugPermPayroll();
    $hrMember = PbUser::create(['name' => 'H', 'email' => 'h'.uniqid().'@x.test', 'password' => 'x', 'role_id' => PbPermission::query()->firstOrFail()->role_id]);
    $this->actingAs($hrMember, 'pb')->get('/p/payroll')->assertOk();
    auth('pb')->logout();

    $page->update(['slug' => 'payroll-2026']);

    expect($this->get('/p/payroll-2026')->getContent())->not->toContain('TOP-SECRET-PAYROLL');
});

it('keeps permission rows through a soft delete and restore', function (): void {
    [$page] = slugPermPayroll();

    $page->delete();
    $page->restore();

    expect($this->get('/p/payroll')->getContent())->not->toContain('TOP-SECRET-PAYROLL');
});

it('drops the permission rows when a page is deleted for good', function (): void {
    [$page] = slugPermPayroll();

    $page->forceDelete();

    expect(PbPermission::query()->where('resource_type', 'page')->where('resource_key', 'payroll')->exists())->toBeFalse();
});

it('leaves other pages permission rows alone on a rename', function (): void {
    slugPermPayroll();
    $other = Page::factory()->create(['slug' => 'other', 'status' => 'published']);

    $other->update(['slug' => 'other-2']);

    expect(PbPermission::query()->where('resource_key', 'payroll')->count())->toBe(1);
});
