<?php

declare(strict_types=1);

/*
 * Regression tests for media storage in 2.0 (GHSA-hj3q-8gc4-6mpw): the stored
 * extension comes from the file's sniffed content type, never the client name,
 * so an image/HTML polyglot can't become an .html page on the app's origin.
 */

use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Services\MediaLibrary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** A real PNG with a script appended, uploaded under the given client name. */
function pngPolyglot(string $clientName): UploadedFile
{
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='); // 1x1 PNG, no GD needed
    $path = tempnam(sys_get_temp_dir(), 'pbpoly');
    file_put_contents($path, $png.'<script>alert(document.domain)</script>');

    return new UploadedFile($path, $clientName, 'image/png', null, true);
}

it('stores a PNG/HTML polyglot named x.html as .png through the panel upload', function (string $name): void {
    Storage::fake('public');

    $this->actingAs(synapseAdmin())->postJson('/ai-page-builder/media/upload', ['files' => [pngPolyglot($name)]])->assertOk();

    $item = MediaItem::query()->firstOrFail();
    expect($item->filename)->toEndWith('.png');
    Storage::disk('public')->assertExists($item->directory.'/'.$item->filename);
})->with(['x.html', 'x.htm', 'x.svg', 'x.shtml', 'noextension']);

it('refuses a file whose sniffed type is not a known media type', function (): void {
    Storage::fake('public');
    config()->set('ai-page-builder.media.accept', []);
    $path = tempnam(sys_get_temp_dir(), 'pbhtml');
    file_put_contents($path, '<!doctype html><html><body><script>alert(1)</script></body></html>');
    $html = new UploadedFile($path, 'page.png', 'image/png', null, true); // lies about its type

    expect(fn () => app(MediaLibrary::class)->store($html))->toThrow(ValidationException::class);
    expect(MediaItem::query()->count())->toBe(0);
});

it('refuses a known type the host left out of media.accept', function (): void {
    Storage::fake('public');
    config()->set('ai-page-builder.media.accept', ['image/jpeg']);

    expect(fn () => app(MediaLibrary::class)->store(pngPolyglot('a.png')))->toThrow(ValidationException::class);
});
