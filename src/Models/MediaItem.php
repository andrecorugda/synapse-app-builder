<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Database\Factories\MediaItemFactory;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function path(): string
    {
        return trim($this->directory, '/').'/'.$this->filename;
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path());
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
