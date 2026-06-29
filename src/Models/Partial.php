<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable partial / symbol — a named snippet of html (+ css) referenced from
 * pages with `<div data-pb-partial="{slug}"></div>`. PageRenderer expands those
 * placeholders at render time, so editing a partial here updates every page that
 * uses it ("edit the header once, change it everywhere").
 *
 * @property string $name
 * @property string $slug
 * @property ?string $html
 * @property ?string $css
 */
class Partial extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return PbSchema::connection();
    }

    public function getTable(): string
    {
        return PbSchema::table('partials');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        // A partial edit must reflect on every page that embeds it — flush the
        // cached page renders. Partial writes are rare (admin action), so the
        // per-page forget is fine.
        $flush = static function (): void {
            try {
                $pageModel = config('ai-page-builder.models.page', Page::class);
                $renderer = app(PageRenderer::class);
                $pageModel::query()->select('slug')->get()
                    ->each(fn ($p) => $renderer->forget($p->slug));
            } catch (\Throwable) {
                // Cache flush is best-effort.
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }
}
