<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Enums\PageStatus;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PageRevision;

it('snapshots the current state into a revision', function (): void {
    $page = Page::factory()->create(['title' => 'Original']);

    $rev = $page->snapshot('save');

    expect($rev)->toBeInstanceOf(PageRevision::class)
        ->and($rev->page_id)->toBe($page->id)
        ->and($rev->action)->toBe('save')
        ->and($rev->title)->toBe('Original')
        ->and($rev->html)->toBe($page->html)
        ->and($rev->css)->toBe($page->css)
        ->and($page->revisions()->count())->toBe(1);
});

it('exposes revisions newest first', function (): void {
    $page = Page::factory()->create();

    $first = $page->snapshot('save');
    $second = $page->snapshot('publish');

    expect($page->revisions()->pluck('id')->all())
        ->toBe([$second->id, $first->id]);
});

it('restores a revision and copies its fields back onto the page', function (): void {
    $page = Page::factory()->create([
        'title' => 'Version one',
        'html' => '<p>one</p>',
        'css' => 'p{color:red}',
    ]);

    // First snapshot captures "Version one".
    $rev = $page->snapshot('save');

    // The page then moves on.
    $page->update([
        'title' => 'Version two',
        'html' => '<p>two</p>',
        'css' => 'p{color:blue}',
    ]);

    $page->restoreRevision($rev);

    $page->refresh();

    expect($page->title)->toBe('Version one')
        ->and($page->html)->toBe('<p>one</p>')
        ->and($page->css)->toBe('p{color:red}');
});

it('snapshots the current state before restoring (reversible restore)', function (): void {
    $page = Page::factory()->create(['title' => 'Old']);
    $rev = $page->snapshot('save');

    $page->update(['title' => 'Current']);

    $page->restoreRevision($rev);

    // A 'before_restore' snapshot must capture the pre-restore "Current" title,
    // plus a 'restore' marker for the roll-back itself.
    $beforeRestore = $page->revisions()->where('action', 'before_restore')->first();

    expect($beforeRestore)->not->toBeNull()
        ->and($beforeRestore->title)->toBe('Current')
        ->and($page->revisions()->where('action', 'restore')->exists())->toBeTrue();
});

it('stamps the current status onto the revision', function (): void {
    $page = Page::factory()->published()->create();

    $rev = $page->snapshot('publish');

    expect($rev->status)->toBe(PageStatus::Published->value);
});
