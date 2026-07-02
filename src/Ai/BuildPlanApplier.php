<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Ai;

use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Watcher;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        private readonly Settings $settings,
        private readonly BuildPlanValidator $validator,
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     * @return array{created:array{collections:list<string>,states:list<string>,functions:list<string>,flows:list<string>,watchers:list<string>,pages:list<string>,partials:list<string>,settings:list<string>},errors:list<string>}
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
                'watchers' => [],
                'pages' => [],
                'partials' => [],
                'settings' => [],
            ],
            'errors' => [],
        ];

        // Validate the plan BEFORE touching the DB. Hard errors (bad slugs,
        // unknown field/node types, …) abort the whole apply so a malicious or
        // malformed key can never reach Schema::create / column DDL via this
        // untrusted path. Advisory "(warning)" entries (e.g. an unknown
        // data-pb-block, or a home_page not in the plan) are NOT blocking.
        $hardErrors = array_values(array_filter(
            $this->validator->validate($plan),
            static fn (string $e): bool => ! str_contains($e, '(warning)'),
        ));

        if ($hardErrors !== []) {
            $summary['errors'] = $hardErrors;

            return $summary;
        }

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
        $this->applyWatchers($build, $summary);
        $this->applyPages($build, $summary);
        $this->applyPartials($build, $summary);
        $this->applySettings($build, $summary);

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
        foreach ($build->partials() as $p) {
            if (is_string($p['slug'] ?? null)) {
                $summary['created']['partials'][] = $p['slug'];
            }
        }
        $home = $build->settings()['home_page'] ?? null;
        if (is_string($home) && $home !== '') {
            $summary['created']['settings'][] = "home_page={$home}";
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
                $model = $this->upsertWithTrashed(PbModel::class,
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
                $this->upsertWithTrashed(FlowFunction::class,
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
                $triggerType = (string) ($flow['trigger_type'] ?? 'manual');
                // UI/route-triggered flows (a page button/form via data-pb-flow, or an
                // external api caller) MUST be public or the /pb-flow route 404s them.
                // Default those to public; manual/collection/cron stay private.
                $isPublic = array_key_exists('is_public', $flow)
                    ? (bool) $flow['is_public']
                    : in_array($triggerType, ['component', 'form', 'api'], true);

                $this->upsertWithTrashed(Flow::class,
                    ['slug' => $slug],
                    [
                        'name' => (string) ($flow['name'] ?? $slug),
                        'trigger_type' => $triggerType,
                        'trigger_config' => is_array($flow['trigger_config'] ?? null) ? $flow['trigger_config'] : [],
                        'definition' => is_array($flow['definition'] ?? null) ? $flow['definition'] : [],
                        'is_active' => (bool) ($flow['is_active'] ?? true),
                        'is_public' => $isPublic,
                    ],
                );
                $summary['created']['flows'][] = $slug;

                // Collection dispatch runs off Watchers (one event → one target),
                // not the flow row — materialize them so the generated automation
                // actually fires. The plan contract stays unchanged.
                if ($triggerType === 'collection') {
                    $this->materializeCollectionWatchers($slug, (array) ($flow['trigger_config'] ?? []));
                }
            } catch (Throwable $e) {
                $summary['errors'][] = "flows[{$i}] ('{$slug}'): ".$e->getMessage();
            }
        }
    }

    /**
     * One Watcher per (collection, event) → this flow, mirroring the legacy
     * back-fill. Idempotent via the natural key, so re-applying a plan updates
     * rather than duplicates.
     *
     * @param  array<string,mixed>  $triggerConfig
     */
    private function materializeCollectionWatchers(string $flowSlug, array $triggerConfig): void
    {
        $collection = $triggerConfig['collection'] ?? null;
        $events = (array) ($triggerConfig['events'] ?? []);
        $criteria = $triggerConfig['criteria'] ?? [];

        if (! is_string($collection) || $collection === '' || $events === []) {
            return;
        }

        foreach ($events as $event) {
            if (! is_string($event) || $event === '') {
                continue;
            }

            $this->upsertWithTrashed(Watcher::class,
                [
                    'source_type' => 'collection',
                    'source_key' => $collection,
                    'event' => $event,
                    'target_key' => $flowSlug,
                ],
                [
                    'name' => sprintf('%s · %s', $flowSlug, $event),
                    'config' => $criteria === [] ? null : ['criteria' => $criteria],
                    'target_type' => 'flow',
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Apply an explicit `watchers` section (exports carry one; AI plans usually
     * don't). Upserted by the natural (source, event, target) key. Runs after
     * applyFlows so flow targets referenced here already exist.
     *
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function applyWatchers(BuildPlan $build, array &$summary): void
    {
        foreach ($build->watchers() as $i => $watcher) {
            $sourceKey = $watcher['source_key'] ?? null;
            $targetKey = $watcher['target_key'] ?? null;

            if (! is_string($sourceKey) || $sourceKey === '' || ! is_string($targetKey) || $targetKey === '') {
                $summary['errors'][] = "watchers[{$i}]: missing source_key or target_key.";

                continue;
            }

            try {
                $sourceType = (string) ($watcher['source_type'] ?? 'collection');
                $event = (string) ($watcher['event'] ?? ($sourceType === 'state' ? 'changed' : 'created'));

                $this->upsertWithTrashed(Watcher::class,
                    [
                        'source_type' => $sourceType,
                        'source_key' => $sourceKey,
                        'event' => $event,
                        'target_key' => $targetKey,
                    ],
                    [
                        'name' => (string) ($watcher['name'] ?? sprintf('%s · %s', $targetKey, $event)),
                        'config' => is_array($watcher['config'] ?? null) && $watcher['config'] !== [] ? $watcher['config'] : null,
                        'target_type' => (string) ($watcher['target_type'] ?? 'flow'),
                        'is_active' => (bool) ($watcher['is_active'] ?? true),
                    ],
                );
                $summary['created']['watchers'][] = "{$sourceType}:{$sourceKey} {$event} → {$targetKey}";
            } catch (Throwable $e) {
                $summary['errors'][] = "watchers[{$i}]: ".$e->getMessage();
            }
        }
    }

    /**
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    /**
     * Upsert by a unique key INCLUDING soft-deleted rows, un-deleting a trashed
     * match. A plain updateOrCreate skips trashed rows, but the unique index still
     * counts them — so re-applying a slug/key that was ever deleted would INSERT
     * and hit a duplicate-key error (which made "edit an existing page/flow/
     * function/collection" impossible once a same-slug row had been trashed). This
     * finds the row trashed-or-not, fills it, un-deletes it, and saves.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string,mixed>  $match
     * @param  array<string,mixed>  $values
     */
    private function upsertWithTrashed(string $modelClass, array $match, array $values): Model
    {
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
        // withTrashed()/getDeletedAtColumn() are SoftDeletes-trait methods, guarded
        // here by $softDeletes — phpstan only sees the base Model, so ignore.
        /** @phpstan-ignore-next-line staticMethod.notFound */
        $query = $softDeletes ? $modelClass::withTrashed() : $modelClass::query();
        /** @var Model $model */
        $model = $query->firstOrNew($match);
        $model->fill($values);
        if ($softDeletes && $model->exists && method_exists($model, 'trashed') && $model->trashed()) {
            /** @phpstan-ignore-next-line method.notFound */
            $model->{$model->getDeletedAtColumn()} = null;
        }
        $model->save();

        return $model;
    }

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
                $kind = is_string($page['kind'] ?? null) ? $page['kind'] : 'page';

                // Styling/behaviour belong in the page's custom_css / custom_js
                // channels (keeps markup clean + the page configurable). Lift any
                // <style>/<script> the model still inlined into those channels so
                // nothing is lost when the html is sanitized below.
                $customCss = is_string($page['custom_css'] ?? null) ? $page['custom_css'] : '';
                $customJs = is_string($page['custom_js'] ?? null) ? $page['custom_js'] : '';
                [$html, $liftedCss, $liftedJs] = $this->liftInlineAssets($html);
                $customCss = trim($customCss."\n".$liftedCss);
                $customJs = trim($customJs."\n".$liftedJs);

                // Safety net: a page used as a send_email template IS an email
                // template even if the plan forgot to mark it (model variance).
                // Email bodies must keep their inline CSS (clients ignore <style>
                // and external sheets) — so don't strip styling out of an email.
                if ($kind !== 'email' && in_array($slug, $this->emailTemplateSlugs($build), true)) {
                    $kind = 'email';
                }

                $this->upsertWithTrashed(Page::class,
                    ['slug' => $slug],
                    [
                        'title' => (string) ($page['title'] ?? $slug),
                        'status' => in_array($status, ['draft', 'published'], true) ? $status : 'draft',
                        'kind' => in_array($kind, ['page', 'email'], true) ? $kind : 'page',
                        'html' => $this->sanitizer->sanitize($html),
                        'css' => is_string($page['css'] ?? null) ? $page['css'] : null,
                        'custom_css' => $customCss !== '' ? $customCss : null,
                        'custom_js' => $customJs !== '' ? $customJs : null,
                    ],
                );
                $summary['created']['pages'][] = $slug;
            } catch (Throwable $e) {
                $summary['errors'][] = "pages[{$i}] ('{$slug}'): ".$e->getMessage();
            }
        }
    }

    /**
     * Apply the plan's `partials` list — reusable chrome (nav / header / footer)
     * embedded on pages via `<div data-pb-partial="<slug>"></div>`. Mirrors
     * applyPages(): upsert by slug, sanitize `html` (AI output is untrusted),
     * keep custom_css/custom_js raw (owner-owned styling/behaviour channels).
     *
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function applyPartials(BuildPlan $build, array &$summary): void
    {
        foreach ($build->partials() as $i => $partial) {
            $slug = $partial['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                $summary['errors'][] = "partials[{$i}]: missing slug.";

                continue;
            }

            try {
                $html = is_string($partial['html'] ?? null) ? $partial['html'] : '';

                // Same channel discipline as pages: lift any inlined <style> into
                // custom_css and drop inline <script> before sanitizing the html.
                $customCss = is_string($partial['custom_css'] ?? null) ? $partial['custom_css'] : '';
                $customJs = is_string($partial['custom_js'] ?? null) ? $partial['custom_js'] : '';
                [$html, $liftedCss] = $this->liftInlineAssets($html);
                $customCss = trim($customCss."\n".$liftedCss);

                Partial::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => (string) ($partial['name'] ?? $slug),
                        'html' => $this->sanitizer->sanitize($html),
                        'css' => is_string($partial['css'] ?? null) ? $partial['css'] : null,
                        'custom_css' => $customCss !== '' ? $customCss : null,
                        'custom_js' => $customJs !== '' ? $customJs : null,
                    ],
                );
                $summary['created']['partials'][] = $slug;
            } catch (Throwable $e) {
                $summary['errors'][] = "partials[{$i}] ('{$slug}'): ".$e->getMessage();
            }
        }
    }

    /**
     * Page slugs referenced as the `template` of any send_email flow node —
     * those pages are email templates regardless of how the plan tagged them.
     *
     * @return list<string>
     */
    private function emailTemplateSlugs(BuildPlan $build): array
    {
        $slugs = [];
        foreach ($build->flows() as $flow) {
            $nodes = $flow['definition']['nodes'] ?? null;
            if (! is_array($nodes)) {
                continue;
            }
            foreach ($nodes as $node) {
                if (is_array($node) && ($node['type'] ?? null) === 'send_email') {
                    $tpl = $node['config']['template'] ?? null;
                    if (is_string($tpl) && $tpl !== '') {
                        $slugs[] = $tpl;
                    }
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Pull any inlined `<style>` out of page html (CSS only) and return
     * [cleanHtml, css, '']. Keeps AI-authored markup clean and routes styling
     * into the page's configurable custom_css channel.
     *
     * `<script>` is NOT lifted here. This is the UNTRUSTED AI/import path, and
     * custom_js is emitted RAW to visitors — lifting a model-authored <script>
     * body into custom_js would turn arbitrary generated JS into an executable
     * payload that bypasses the html sanitizer entirely. So inline <script>
     * bodies are simply DROPPED (the sanitizer would strip the tag from html
     * anyway; we just make sure the body isn't smuggled into custom_js).
     *
     * @return array{0:string,1:string,2:string}
     */
    private function liftInlineAssets(string $html): array
    {
        $css = [];

        $html = preg_replace_callback('#<style\b[^>]*>(.*?)</style>#is', function (array $m) use (&$css): string {
            $css[] = trim($m[1]);

            return '';
        }, $html) ?? $html;

        // Drop inline <script> bodies on this untrusted path — do NOT move them
        // into the raw-emitted custom_js channel.
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;

        return [$html, trim(implode("\n", array_filter($css))), ''];
    }

    /**
     * Apply app-level settings (currently the home page). Best-effort like the
     * rest; the home_page slug should name a page the plan also creates.
     *
     * @param  array{created:array<string,list<string>>,errors:list<string>}  $summary
     */
    private function applySettings(BuildPlan $build, array &$summary): void
    {
        $settings = $build->settings();

        // Page-pointer settings (slug of a page): home + the 404/maintenance pages.
        foreach (['home_page', 'not_found_page', 'maintenance_page'] as $key) {
            $value = $settings[$key] ?? null;
            if (is_string($value) && $value !== '') {
                try {
                    $this->settings->set($key, $value);
                    $summary['created']['settings'][] = "{$key}={$value}";
                } catch (Throwable $e) {
                    $summary['errors'][] = "settings.{$key} ('{$value}'): ".$e->getMessage();
                }
            }
        }

        // Maintenance-mode toggle (boolean).
        if (array_key_exists('maintenance_mode', $settings)) {
            try {
                $on = (bool) $settings['maintenance_mode'];
                $this->settings->set('maintenance_mode', $on);
                $summary['created']['settings'][] = 'maintenance_mode='.($on ? 'on' : 'off');
            } catch (Throwable $e) {
                $summary['errors'][] = 'settings.maintenance_mode: '.$e->getMessage();
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
