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

it('deletes the physical file when the row is deleted', function (): void {
    $item = MediaItem::factory()->create(['disk' => 'public']);
    Storage::disk('public')->put($item->path(), 'x');

    $item->delete();

    Storage::disk('public')->assertMissing($item->path());
});

it('deletes the file from the item own disk, not the default one', function (): void {
    Storage::fake('pb-cloud');
    $item = MediaItem::factory()->create(['disk' => 'pb-cloud']);
    Storage::disk('pb-cloud')->put($item->path(), 'x');

    $item->delete();

    Storage::disk('pb-cloud')->assertMissing($item->path());
});

it('still deletes the row when the file cleanup fails', function (): void {
    $item = MediaItem::factory()->create(['disk' => 'missing-disk']);

    $item->delete();

    expect(MediaItem::count())->toBe(0);
});

it('exposes the library as asset-manager entries', function (): void {
    MediaItem::factory()->count(3)->create();

    $assets = app(MediaLibrary::class)->assets();

    expect($assets)->toHaveCount(3)
        ->and($assets[0])->toHaveKeys(['src', 'name', 'type']);
});
