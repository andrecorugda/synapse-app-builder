<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;
use Illuminate\Database\Eloquent\Model;

/**
 * Rewrites media URLs baked into saved page / partial content — the compiled
 * HTML, CSS and the canonical GrapesJS project_data — after media files move
 * to another disk (see MigrateMediaCommand). Editors persist absolute or
 * root-relative URL strings at edit time, so moving the physical files alone
 * would leave every existing page pointing at the old location.
 *
 * Revision tables (page_revisions, record_revisions) are intentionally left
 * untouched: history should keep rendering what was saved at the time.
 */
class MediaUrlRewriter
{
    private const PAGE_COLUMNS = ['html', 'css', 'custom_css', 'custom_js'];

    private const PARTIAL_COLUMNS = ['html', 'css', 'custom_css', 'custom_js'];

    /**
     * Replace every occurrence of the map's keys (old URL) with its values
     * (new URL) across all pages — every kind, including email templates and
     * soft-deleted pages — and all partials.
     *
     * @param  array<string,string>  $map  old URL => new URL
     * @return array<string,int> Replacement counts keyed "entity id/slug column".
     */
    public function rewrite(array $map, bool $dryRun = false): array
    {
        $map = $this->sortedMap($map);
        if ($map === []) {
            return [];
        }

        $report = [];

        /** @var class-string<Page> $pageModel */
        $pageModel = config('ai-page-builder.models.page', Page::class);
        $pageModel::withTrashed()->chunkById(50, function ($pages) use ($map, $dryRun, &$report): void {
            foreach ($pages as $page) {
                /** @var Page $page */
                $this->rewriteModel($page, self::PAGE_COLUMNS, $map, $dryRun, 'page '.$page->slug, $report);
            }
        });

        Partial::query()->chunkById(50, function ($partials) use ($map, $dryRun, &$report): void {
            foreach ($partials as $partial) {
                /** @var Partial $partial */
                $this->rewriteModel($partial, self::PARTIAL_COLUMNS, $map, $dryRun, 'partial '.$partial->getKey(), $report);
            }
        });

        return $report;
    }

    /**
     * @param  array<int,string>  $columns
     * @param  array<string,string>  $map
     * @param  array<string,int>  $report
     */
    private function rewriteModel(Model $model, array $columns, array $map, bool $dryRun, string $label, array &$report): void
    {
        $search = array_keys($map);
        $replace = array_values($map);

        foreach ($columns as $column) {
            $value = $model->getAttribute($column);
            if (! is_string($value) || $value === '') {
                continue;
            }

            $updated = str_replace($search, $replace, $value, $count);
            if ($count > 0) {
                $model->setAttribute($column, $updated);
                $report[$label.' '.$column] = ($report[$label.' '.$column] ?? 0) + $count;
            }
        }

        // project_data is an array cast — walk the decoded tree and replace on
        // string leaves. Replacing on the raw JSON would miss URLs that
        // json_encode escaped ("\/") and could corrupt the document.
        $data = $model->getAttribute('project_data');
        if (is_array($data)) {
            $count = 0;
            $updated = $this->rewriteArray($data, $search, $replace, $count);
            if ($count > 0) {
                $model->setAttribute('project_data', $updated);
                $report[$label.' project_data'] = ($report[$label.' project_data'] ?? 0) + $count;
            }
        }

        if ($model->isDirty()) {
            $dryRun ? $model->discardChanges() : $model->save();
        }
    }

    /**
     * @param  array<mixed>  $data
     * @param  array<int,string>  $search
     * @param  array<int,string>  $replace
     * @return array<mixed>
     */
    private function rewriteArray(array $data, array $search, array $replace, int &$count): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = str_replace($search, $replace, $value, $hits);
                $count += $hits;
            } elseif (is_array($value)) {
                $data[$key] = $this->rewriteArray($value, $search, $replace, $count);
            }
        }

        return $data;
    }

    /**
     * Longest keys first so a URL that prefixes another (e.g. `/a.jpg` vs
     * `/a.jpg.webp`) can never clobber the longer match.
     *
     * @param  array<string,string>  $map
     * @return array<string,string>
     */
    private function sortedMap(array $map): array
    {
        $map = array_filter(
            $map,
            static fn (string $new, string $old): bool => $old !== '' && $old !== $new,
            ARRAY_FILTER_USE_BOTH,
        );

        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $map;
    }
}
