<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Database\Factories\MediaItemFactory;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One stored media asset for the builder's media library / picker.
 *
 * @property int $id
 * @property string $disk
 * @property string $directory
 * @property string $filename
 * @property string $name
 * @property ?string $mime_type
 * @property ?int $size
 * @property ?int $width
 * @property ?int $height
 * @property ?string $alt
 */
class MediaItem extends Model
{
    /** @use HasFactory<MediaItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('media');
    }

    protected static function booted(): void
    {
        // Deleting the row must also delete the physical file — otherwise it
        // stays on the (possibly cloud) disk forever, still reachable by URL.
        // Best-effort: a storage failure (disk gone, adapter uninstalled,
        // network) must never block deleting the row itself.
        static::deleted(static function (self $item): void {
            try {
                Storage::disk($item->disk)->delete($item->path());
            } catch (\Throwable $e) {
                Log::warning('[ai-page-builder] Could not delete media file after row deletion.', [
                    'disk' => $item->disk,
                    'path' => $item->path(),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function path(): string
    {
        return trim($this->directory, '/').'/'.$this->filename;
    }

    public function url(): string
    {
        $raw = Storage::disk($this->disk)->url($this->path());

        // Local disks build their URL from APP_URL, which is frequently wrong in
        // development (e.g. missing the dev server port). Return a root-relative
        // URL in that case so the browser resolves it against the current origin
        // (correct host:port). Genuinely remote URLs (S3 / CDN) are left intact —
        // including a different PORT on the same host (e.g. MinIO on :9000 next
        // to the app), which must not be stripped to the app origin.
        $app = parse_url((string) config('app.url'));
        $url = parse_url($raw);
        $sameOrigin = ($url['host'] ?? null) === ($app['host'] ?? null)
            && ($url['port'] ?? null) === ($app['port'] ?? null);

        if (! isset($url['host']) || $sameOrigin) {
            return $url['path'] ?? $raw;
        }

        return $raw;
    }

    /**
     * GrapesJS asset-manager shape for this item.
     *
     * @return array{src:string,name:string,type:string,height:?int,width:?int}
     */
    public function toAsset(): array
    {
        return [
            'src' => $this->url(),
            'name' => $this->name,
            'type' => 'image',
            'height' => $this->height,
            'width' => $this->width,
        ];
    }

    protected static function newFactory(): MediaItemFactory
    {
        return MediaItemFactory::new();
    }
}
