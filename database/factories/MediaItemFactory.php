<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Database\Factories;

use Andre\AiPageBuilder\Models\MediaItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaItem>
 */
class MediaItemFactory extends Factory
{
    protected $model = MediaItem::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true).'.png';

        return [
            'disk' => 'public',
            'directory' => 'page-builder',
            'filename' => Str::random(20).'.png',
            'name' => $name,
            'mime_type' => 'image/png',
            'size' => $this->faker->numberBetween(1000, 500000),
            'width' => 800,
            'height' => 600,
            'alt' => null,
        ];
    }
}
