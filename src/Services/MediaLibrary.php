<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\MediaItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores uploaded files on the configured disk and records MediaItem rows,
 * and exposes the library as GrapesJS asset-manager entries.
 */
class MediaLibrary
{
    /** @return class-string<MediaItem> */
    public function model(): string
    {
        /** @var class-string<MediaItem> */
        return config('ai-page-builder.models.media', MediaItem::class);
    }

    /**
     * Sniffed MIME type => the only extension a file of that type is stored
     * with. Anything else is refused: the client's file name never chooses the
     * extension, so a PNG/HTML polyglot named `x.html` is stored as `.png` and
     * can't be served as a page on the app's origin.
     */
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg', // only reachable when a host re-adds it to media.accept
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    /**
     * @throws ValidationException when the file's sniffed type is not storable
     */
    public function store(UploadedFile $file, ?int $userId = null): MediaItem
    {
        $disk = app(MediaStorage::class)->diskName();
        $dir = trim((string) config('ai-page-builder.media.directory', 'page-builder'), '/');

        $ext = $this->extensionFor($file);
        $filename = Str::random(28).'.'.$ext;

        Storage::disk($disk)->putFileAs($dir, $file, $filename);

        [$width, $height] = $this->dimensions($file);

        $model = $this->model();

        return $model::create([
            'disk' => $disk,
            'directory' => $dir,
            'filename' => $filename,
            'name' => $file->getClientOriginalName() ?: $filename,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'created_by' => $userId,
        ]);
    }

    /**
     * The storage extension for a file, from its SNIFFED content type (never
     * the client name). Refuses types outside the known-safe map and, when the
     * host configured `media.accept`, types outside that list too.
     *
     * @throws ValidationException
     */
    public function extensionFor(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());
        $accept = array_map('strtolower', (array) config('ai-page-builder.media.accept', []));

        if (! isset(self::EXTENSIONS[$mime]) || ($accept !== [] && ! in_array($mime, $accept, true))) {
            throw ValidationException::withMessages([
                'file' => "Files of type {$mime} can't be uploaded.",
            ]);
        }

        return self::EXTENSIONS[$mime];
    }

    /**
     * All media as GrapesJS asset-manager entries (newest first).
     *
     * @return array<int,array<string,mixed>>
     */
    public function assets(): array
    {
        return $this->model()::query()
            ->latest('id')
            ->get()
            ->map(static fn (MediaItem $m): array => $m->toAsset())
            ->all();
    }

    /**
     * @return array{0:?int,1:?int} [width, height]
     */
    private function dimensions(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        if (! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return [null, null];
        }

        $info = @getimagesize($file->getRealPath());

        return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
    }
}
