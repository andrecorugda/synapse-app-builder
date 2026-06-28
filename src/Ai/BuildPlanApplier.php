<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Ai;

use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Throwable;

/**
 * Applies a validated build plan by driving the existing data services. It is
 * the only writer in the AI layer — everything it does goes through the same
 * services the REST API / Filament use, so AI-built artifacts behave like
 * hand-built ones.
 *
 * Guarantees:
 *  - Idempotent: collections/states/functions/flows/pages upsert by key/slug.
 *  - Safe HTML: page html is run through HtmlSanitizer (AI output is untrusted).
 *  - Best-effort: each artifact is applied independently and per-item failures
 *    are reported in the summary (no global transaction — collection DDL
 *    auto-commits on MySQL; re-applying the plan completes anything missed).
 *  - dryRun: reports what WOULD be created without writing.
 */
class BuildPlanApplier
{
    public function __construct(
        private readonly SchemaSynchronizer $schema,
        private readonly RecordQuery $records,
        private readonly VariableStore $variables,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     * @return array{created:array{collections:list<string>,states:list<string>,functions:list<string>,flows:list<string>,pages:list<string>},errors:list<string>}
     */
    public function apply(array $plan, bool $dryRun = false): array
    {
        $build = BuildPlan::fromArray($plan);

        $summary = [
            'created' => [
                'collections' => [],
                'states' => [],
                'functions' => [],
                'flows' => [],
                'pages' => [],
            ],
            'errors' => [],
        ];

        if ($dryRun) {
            $this->plan($build, $summary);

            return $summary;
        }

        // No global transaction: creating a collection runs DDL (Schema::create),
        // which auto-commits on MySQL and ends any open transaction — so wrapping
        // apply in one transaction breaks there. Instead apply is best-effort +
        // idempotent: each artifact upserts independently, per-item failures are
        // recorded, and re-applying the plan safely completes anything missed.
        $this->applyCollections($build, $summary);
        $this->applyStates($build, $summary);
        $this->applyFunctions($build, $summary);
        $this->applyFlows($build, $summary);
        $this->applyPages($build, $summary);

        return $summary;
    }

    /**
     * dryRun: describe what would be created without touching the DB.
     *
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function plan(BuildPlan $build, array &$summary): void
    {
        foreach ($build->collections() as $c) {
            if (is_string($c['key'] ?? null)) {
                $summary['created']['collections'][] = $c['key'];
            }
        }
        foreach ($build->states() as $s) {
            if (is_string($s['key'] ?? null)) {
                $summary['created']['states'][] = $s['key'];
            }
        }
        foreach ($build->functions() as $f) {
            if (is_string($f['slug'] ?? null)) {
                $summary['created']['functions'][] = $f['slug'];
            }
        }
        foreach ($build->flows() as $f) {
            if (is_string($f['slug'] ?? null)) {
                $summary['created']['flows'][] = $f['slug'];
            }
        }
        foreach ($build->pages() as $p) {
            if (is_string($p['slug'] ?? null)) {
                $summary['created']['pages'][] = $p['slug'];
            }
        }
    }

    /**
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function applyCollections(BuildPlan $build, array &$summary): void
    {
        foreach ($build->collections() as $i => $collection) {
            $key = $collection['key'] ?? null;
            if (! is_string($key) || $key === '') {
                $summary['errors'][] = "collections[{$i}]: missing key.";

                continue;
            }

            try {
                /** @var PbModel $model */
                $model = PbModel::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'name' => (string) ($collection['name'] ?? $key),
                        'table_name' => PbModel::physicalTableName($key),
                        'has_timestamps' => (bool) ($collection['has_timestamps'] ?? true),
                        'has_soft_deletes' => (bool) ($collection['has_soft_deletes'] ?? false),
                    ],
                );

                $this->syncFields($model, $this->fieldList($collection));

                // Reload field relation so the synchronizer sees the new set.
                $model->load('fields');
                $this->schema->sync($model);

                $this->seedRecords($model, $collection, $i, $summary);

                $summary['created']['collections'][] = $key;
            } catch (Throwable $e) {
                $summary['errors'][] = "collections[{$i}] ('{$key}'): ".$e->getMessage();
            }
        }
    }

    /**
     * Upsert the field rows for a collection by key.
     *
     * @param  list<array<string,mixed>>  $fields
     */
    private function syncFields(PbModel $model, array $fields): void
    {
        $sort = 0;
        foreach ($fields as $field) {
            $fieldKey = $field['key'] ?? null;
            if (! is_string($fieldKey) || $fieldKey === '') {
                continue;
            }

            PbField::query()->updateOrCreate(
                ['model_id' => $model->id, 'key' => $fieldKey],
                [
                    'label' => (string) ($field['label'] ?? $fieldKey),
                    'type' => (string) ($field['type'] ?? 'string'),
                    'options' => is_array($field['options'] ?? null) ? $field['options'] : [],
                    'sort' => $sort++,
                ],
            );
        }
    }

    /**
     * Seed rows go through RecordQuery so they are validated + column-mapped
     * like any other write. Seeding is best-effort: a bad seed row is reported
     * but does not abort the collection (the schema is already in place).
     *
     * @param  array<string,mixed>  $collection
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function seedRecords(PbModel $model, array $collection, int $i, array &$summary): void
    {
        $seed = $collection['seed'] ?? [];
        if (! is_array($seed)) {
            return;
        }

        foreach ($seed as $si => $row) {
            if (! is_array($row)) {
                $summary['errors'][] = "collections[{$i}].seed[{$si}]: row must be an object.";

                continue;
            }

            try {
                /** @var array<string,mixed> $row */
                $this->records->create($model, $row);
            } catch (Throwable $e) {
                $summary['errors'][] = "collections[{$i}].seed[{$si}]: ".$e->getMessage();
            }
        }
    }

    /**
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function applyStates(BuildPlan $build, array &$summary): void
    {
        foreach ($build->states() as $i => $state) {
            $key = $state['key'] ?? null;
            if (! is_string($key) || $key === '') {
                $summary['errors'][] = "states[{$i}]: missing key.";

                continue;
            }

            try {
                $type = is_string($state['type'] ?? null) ? $state['type'] : null;
                $this->variables->set($key, $state['value'] ?? null, $type);
                $summary['created']['states'][] = $key;
            } catch (Throwable $e) {
                $summary['errors'][] = "states[{$i}] ('{$key}'): ".$e->getMessage();
            }
        }
    }

    /**
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function applyFunctions(BuildPlan $build, array &$summary): void
    {
        foreach ($build->functions() as $i => $fn) {
            $slug = $fn['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                $summary['errors'][] = "functions[{$i}]: missing slug.";

                continue;
            }

            try {
                FlowFunction::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => (string) ($fn['name'] ?? $slug),
                        'description' => isset($fn['description']) ? (string) $fn['description'] : null,
                        'runtime' => (string) ($fn['runtime'] ?? 'expression'),
                        'body' => isset($fn['body']) ? (string) $fn['body'] : null,
                    ],
                );
                $summary['created']['functions'][] = $slug;
            } catch (Throwable $e) {
                $summary['errors'][] = "functions[{$i}] ('{$slug}'): ".$e->getMessage();
            }
        }
    }

    /**
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function applyFlows(BuildPlan $build, array &$summary): void
    {
        foreach ($build->flows() as $i => $flow) {
            $slug = $flow['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                $summary['errors'][] = "flows[{$i}]: missing slug.";

                continue;
            }

            try {
                Flow::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => (string) ($flow['name'] ?? $slug),
                        'trigger_type' => (string) ($flow['trigger_type'] ?? 'manual'),
                        'trigger_config' => is_array($flow['trigger_config'] ?? null) ? $flow['trigger_config'] : [],
                        'definition' => is_array($flow['definition'] ?? null) ? $flow['definition'] : [],
                        'is_active' => (bool) ($flow['is_active'] ?? true),
                    ],
                );
                $summary['created']['flows'][] = $slug;
            } catch (Throwable $e) {
                $summary['errors'][] = "flows[{$i}] ('{$slug}'): ".$e->getMessage();
            }
        }
    }

    /**
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function applyPages(BuildPlan $build, array &$summary): void
    {
        foreach ($build->pages() as $i => $page) {
            $slug = $page['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                $summary['errors'][] = "pages[{$i}]: missing slug.";

                continue;
            }

            try {
                $html = is_string($page['html'] ?? null) ? $page['html'] : '';
                $status = is_string($page['status'] ?? null) ? $page['status'] : 'draft';

                Page::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'title' => (string) ($page['title'] ?? $slug),
                        'status' => in_array($status, ['draft', 'published'], true) ? $status : 'draft',
                        'html' => $this->sanitizer->sanitize($html),
                        'css' => is_string($page['css'] ?? null) ? $page['css'] : null,
                    ],
                );
                $summary['created']['pages'][] = $slug;
            } catch (Throwable $e) {
                $summary['errors'][] = "pages[{$i}] ('{$slug}'): ".$e->getMessage();
            }
        }
    }

    /**
     * Normalise a collection's fields into a list of arrays.
     *
     * @param  array<string,mixed>  $collection
     * @return list<array<string,mixed>>
     */
    private function fieldList(array $collection): array
    {
        $fields = $collection['fields'] ?? [];
        if (! is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $field) {
            if (is_array($field)) {
                /** @var array<string,mixed> $field */
                $out[] = $field;
            }
        }

        return $out;
    }
}
