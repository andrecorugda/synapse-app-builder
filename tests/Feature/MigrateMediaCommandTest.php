<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\MediaStorage;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('target', ['url' => 'https://cdn.example.com/media']);
});

function seedMediaFile(string $filename, string $contents = 'png-bytes'): MediaItem
{
    Storage::disk('public')->put('page-builder/'.$filename, $contents);

    return MediaItem::factory()->create([
        'disk' => 'public',
        'directory' => 'page-builder',
        'filename' => $filename,
    ]);
}

it('migrates files, flips rows and rewrites saved content', function (): void {
    $a = seedMediaFile('a.png');
    $b = seedMediaFile('b.png');

    $page = Page::factory()->create(['html' => '<img src="'.$a->url().'">']);
    $email = Page::factory()->create(['kind' => 'email', 'html' => '<img src="'.$b->url().'">']);

    $this->artisan('ai-page-builder:migrate-media', ['--disk' => 'target'])
        ->assertExitCode(0);

    Storage::disk('target')->assertExists('page-builder/a.png');
    Storage::disk('target')->assertExists('page-builder/b.png');
    // Originals are kept by default.
    Storage::disk('public')->assertExists('page-builder/a.png');

    expect($a->refresh()->disk)->toBe('target')
        ->and($b->refresh()->disk)->toBe('target')
        ->and($a->url())->toBe('https://cdn.example.com/media/page-builder/a.png')
        ->and($page->refresh()->html)->toContain('https://cdn.example.com/media/page-builder/a.png')
        ->and($email->refresh()->html)->toContain('https://cdn.example.com/media/page-builder/b.png');
});

it('changes nothing on a dry run', function (): void {
    $a = seedMediaFile('a.png');
    $page = Page::factory()->create(['html' => '<img src="'.$a->url().'">']);
    $oldUrl = $a->url();

    $this->artisan('ai-page-builder:migrate-media', ['--disk' => 'target', '--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertExitCode(0);

    Storage::disk('target')->assertMissing('page-builder/a.png');
    expect($a->refresh()->disk)->toBe('public')
        ->and($page->refresh()->html)->toContain($oldUrl);
});

it('deletes the source file only when --delete-source is passed', function (): void {
    seedMediaFile('a.png');

    $this->artisan('ai-page-builder:migrate-media', ['--disk' => 'target', '--delete-source' => true])
        ->assertExitCode(0);

    Storage::disk('target')->assertExists('page-builder/a.png');
    Storage::disk('public')->assertMissing('page-builder/a.png');
});

it('is idempotent — a second run finds nothing to migrate', function (): void {
    seedMediaFile('a.png');

    $this->artisan('ai-page-builder:migrate-media', ['--disk' => 'target'])->assertExitCode(0);
    $this->artisan('ai-page-builder:migrate-media', ['--disk' => 'target'])
        ->expectsOutputToContain('Nothing to migrate')
        ->assertExitCode(0);
});

it('skips rows whose file is missing, migrates the rest, and exits non-zero', function (): void {
    $ok = seedMediaFile('ok.png');
    $ghost = MediaItem::factory()->create([
        'disk' => 'public',
        'directory' => 'page-builder',
        'filename' => 'ghost.png', // row exists, file does not
    ]);

    $this->artisan('ai-page-builder:migrate-media', ['--disk' => 'target'])
        ->assertExitCode(1);

    expect($ok->refresh()->disk)->toBe('target')
        ->and($ghost->refresh()->disk)->toBe('public');
    Storage::disk('target')->assertExists('page-builder/ok.png');
});

it('fails with guidance when no target disk is available', function (): void {
    seedMediaFile('a.png');

    $this->artisan('ai-page-builder:migrate-media')
        ->expectsOutputToContain('No target disk')
        ->assertExitCode(1);
});

it('defaults to the configured cloud disk as the target', function (): void {
    $settings = app(Settings::class);
    $settings->set('storage.driver', 's3');
    $settings->set('storage.s3.key', 'AKIAEXAMPLE');
    $settings->setEncrypted('storage.s3.secret', 'super-secret');
    $settings->set('storage.s3.region', 'eu-west-1');
    $settings->set('storage.s3.bucket', 'synapse-media');
    Storage::fake(MediaStorage::DISK);

    $item = seedMediaFile('a.png');

    $this->artisan('ai-page-builder:migrate-media')->assertExitCode(0);

    expect($item->refresh()->disk)->toBe(MediaStorage::DISK);
    Storage::disk(MediaStorage::DISK)->assertExists('page-builder/a.png');
});
