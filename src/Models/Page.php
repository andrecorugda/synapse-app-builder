<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Ai\HtmlSanitizer;
use Andre\AiPageBuilder\Database\Factories\PageFactory;
use Andre\AiPageBuilder\Enums\PageStatus;
use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property bool $requires_auth
 * @property ?string $template
 * @property ?array $project_data
 * @property ?string $html
 * @property ?string $css
 * @property ?string $custom_css
 * @property ?string $custom_js
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
        'requires_auth' => 'boolean',
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

    /**
     * Version history, newest first.
     *
     * @return HasMany<PageRevision, $this>
     */
    public function revisions(): HasMany
    {
        /** @var class-string<PageRevision> $revisionModel */
        $revisionModel = config('ai-page-builder.models.page_revision', PageRevision::class);

        // Newest first; order by id (not created_at) so same-second snapshots
        // stay deterministically ordered.
        return $this->hasMany($revisionModel)->orderByDesc('id');
    }

    /**
     * Snapshot the page's current editable state into a new revision.
     */
    public function snapshot(string $action = 'save'): PageRevision
    {
        $status = $this->status instanceof PageStatus ? $this->status->value : $this->status;

        /** @var PageRevision */
        return $this->revisions()->create([
            'action' => $action,
            'title' => $this->title,
            'status' => $status,
            'project_data' => $this->project_data,
            'html' => $this->html,
            'css' => $this->css,
            'custom_css' => $this->custom_css,
            'custom_js' => $this->custom_js,
            'meta' => $this->meta,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Roll the page back to a prior revision, snapshotting the current state
     * first so the restore is itself reversible.
     */
    public function restoreRevision(PageRevision $rev): void
    {
        $this->snapshot('before_restore');

        $this->forceFill([
            'title' => $rev->title,
            'status' => $rev->status,
            'project_data' => $rev->project_data,
            'html' => $rev->html,
            'css' => $rev->css,
            'custom_css' => $rev->custom_css,
            'custom_js' => $rev->custom_js,
            'meta' => $rev->meta,
        ])->save();

        $this->snapshot('restore');
    }

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    protected static function booted(): void
    {
        // Sanitize the rendered `html` snapshot on EVERY write path — the
        // GrapesJS editor save, the REST API, AND the AI/import applier — not
        // just the AI path. `html` is served verbatim to visitors, so it is the
        // XSS surface; running it through HtmlSanitizer here closes the gap where
        // a hand-built editor save could persist a <script>/onload payload.
        //
        // `custom_css` / `custom_js` are intentionally NOT sanitized: they are
        // the trusted-author raw escape hatch and are emitted raw by design.
        static::saving(static function (Page $page): void {
            if ($page->isDirty('html') && is_string($page->html) && $page->html !== '') {
                $page->html = app(HtmlSanitizer::class)->sanitize($page->html);
            }
        });

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
