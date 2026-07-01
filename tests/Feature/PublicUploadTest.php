<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

it('lets an authenticated user upload a valid PNG and returns 201 with a url', function (): void {
    $this->actingAs(new User);

    // Use fake()->create() instead of fake()->image() to avoid GD dependency
    // (the host lacks GD — pre-existing 2 failures in MediaUploadTest confirm this).
    $file = UploadedFile::fake()->create('photo.png', 10, 'image/png');

    $res = $this->postJson('/pb-upload', ['file' => $file]);

    $res->assertStatus(201)
        ->assertJsonStructure(['url']);

    expect(MediaItem::count())->toBe(1);

    $item = MediaItem::first();
    Storage::disk('public')->assertExists($item->path());
});

it('rejects an anonymous upload when allow_anonymous is false (default)', function (): void {
    config(['ai-page-builder.uploads.allow_anonymous' => false]);

    $file = UploadedFile::fake()->create('photo.png', 10, 'image/png');

    $res = $this->postJson('/pb-upload', ['file' => $file]);

    $res->assertStatus(403);

    expect(MediaItem::count())->toBe(0);
});

it('allows an anonymous upload when allow_anonymous is true', function (): void {
    config(['ai-page-builder.uploads.allow_anonymous' => true]);

    $file = UploadedFile::fake()->create('photo.png', 10, 'image/png');

    $res = $this->postJson('/pb-upload', ['file' => $file]);

    $res->assertStatus(201)
        ->assertJsonStructure(['url']);

    expect(MediaItem::count())->toBe(1);
});

it('rejects a non-image file upload with 422', function (): void {
    config(['ai-page-builder.uploads.allow_anonymous' => true]);

    $file = UploadedFile::fake()->create('shell.php', 5, 'text/plain');

    $res = $this->postJson('/pb-upload', ['file' => $file]);

    $res->assertStatus(422);

    expect(MediaItem::count())->toBe(0);
});

it('rejects an oversize file with 422', function (): void {
    config(['ai-page-builder.uploads.allow_anonymous' => true]);

    // max_kb default is 5120; send a 6 MB file
    $maxKb = (int) config('ai-page-builder.uploads.max_kb', 5120);
    $file = UploadedFile::fake()->create('big.png', $maxKb + 1024, 'image/png');

    $res = $this->postJson('/pb-upload', ['file' => $file]);

    $res->assertStatus(422);

    expect(MediaItem::count())->toBe(0);
});
