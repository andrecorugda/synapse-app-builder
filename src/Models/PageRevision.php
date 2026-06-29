<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of a {@see Page}'s editable state, taken on save,
 * publish, or restore. Lets editors browse history and roll a page back.
 *
 * @property int $id
 * @property int $page_id
 * @property string $action save|publish|restore|before_restore
 * @property ?string $title
 * @property ?string $status
 * @property ?array $project_data
 * @property ?string $html
 * @property ?string $css
 * @property ?string $custom_css
 * @property ?string $custom_js
 * @property ?array $meta
 * @property ?int $created_by
 * @property ?Carbon $created_at
 */
class PageRevision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'project_data' => 'array',
        'meta' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('page_revisions');
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        /** @var class-string<Page> $pageModel */
        $pageModel = config('ai-page-builder.models.page', Page::class);

        return $this->belongsTo($pageModel);
    }
}
