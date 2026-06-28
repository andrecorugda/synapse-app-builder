<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Database\Factories;

use Andre\AiPageBuilder\Enums\PageStatus;
use Andre\AiPageBuilder\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        $title = ucfirst($this->faker->words(3, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'status' => PageStatus::Draft,
            'template' => null,
            'project_data' => null,
            'html' => '<section data-pb-block="hero" class="pb-hero"><h1 class="pb-hero__title">'.$title.'</h1></section>',
            'css' => '.pb-hero{padding:4rem 1.5rem;text-align:center;}',
            'meta' => ['description' => $this->faker->sentence()],
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PageStatus::Published,
            'published_at' => now(),
        ]);
    }
}
