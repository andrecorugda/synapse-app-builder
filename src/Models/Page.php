<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Database\Factories\PageFactory;
use Andre\AiPageBuilder\Enums\PageStatus;
use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A builder page.
 *
 * `project_data` is the canonical GrapesJS state (editor.getProjectData()) and
 * the editable source of truth; `html`/`css` are the compiled render snapshot
 * served to visitors.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property PageStatus $status
 * @property string $kind
 * @property ?string $template
 * @property ?array $project_data
 * @property ?string $html
 * @property ?string $css
 * @property ?array $meta
 * @property ?Carbon $published_at
 */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'status' => PageStatus::class,
        'project_data' => 'array',
        'meta' => 'array',
        'published_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('pages');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PageStatus::Published->value);
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePages(Builder $query): Builder
    {
        return $query->where('kind', 'page');
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeEmailTemplates(Builder $query): Builder
    {
        return $query->where('kind', 'email');
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published;
    }

    public function isEmailTemplate(): bool
    {
        return $this->kind === 'email';
    }

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    protected static function booted(): void
    {
        $bust = static function (Page $page): void {
            $renderer = app(PageRenderer::class);
            $renderer->forget($page->slug);

            // If the slug changed, also clear the stale entry.
            $original = $page->getOriginal('slug');
            if (is_string($original) && $original !== '' && $original !== $page->slug) {
                $renderer->forget($original);
            }
        };

        static::saved($bust);
        static::deleted($bust);
    }
}
