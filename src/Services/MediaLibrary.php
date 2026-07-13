<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\MediaItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function store(UploadedFile $file, ?int $userId = null): MediaItem
    {
        $disk = app(MediaStorage::class)->diskName();
        $dir = trim((string) config('ai-page-builder.media.directory', 'page-builder'), '/');

        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
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
