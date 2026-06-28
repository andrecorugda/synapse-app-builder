<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Services\MediaLibrary;
use Andre\AiPageBuilder\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

it('stores an upload and returns GrapesJS asset-manager JSON', function (): void {
    $this->actingAs(new User);

    $res = $this->postJson('/ai-page-builder/media/upload', [
        'files' => [UploadedFile::fake()->image('hero.png', 800, 600)],
    ]);

    $res->assertOk()
        ->assertJsonStructure(['data' => [['src', 'name', 'type']]])
        ->assertJsonPath('data.0.type', 'image');

    expect(MediaItem::count())->toBe(1);

    $item = MediaItem::first();
    expect($item->name)->toBe('hero.png')
        ->and($item->width)->toBe(800)
        ->and($item->height)->toBe(600);

    Storage::disk('public')->assertExists($item->path());
});

it('rejects the upload endpoint without authentication', function (): void {
    $res = $this->postJson('/ai-page-builder/media/upload', [
        'files' => [UploadedFile::fake()->image('x.png')],
    ]);

    // auth middleware → 401/403/redirect, never a successful store
    expect($res->status())->not->toBe(200);
    expect(MediaItem::count())->toBe(0);
});

it('exposes the library as asset-manager entries', function (): void {
    MediaItem::factory()->count(3)->create();

    $assets = app(MediaLibrary::class)->assets();

    expect($assets)->toHaveCount(3)
        ->and($assets[0])->toHaveKeys(['src', 'name', 'type']);
});
