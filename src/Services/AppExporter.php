<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Variable;
use Andre\AiPageBuilder\Models\Watcher;
use Throwable;

/**
 * Serialise a whole Synapse app — collections (+fields), states, functions,
 * flows, pages, and the relevant app settings — into ONE plan-shaped array.
 *
 * The output is the EXACT shape {@see BuildPlanApplier}
 * reads, so an export round-trips straight back through the applier (import).
 * Keeping export and apply on one shape means a re-import recreates the app
 * idempotently by key/slug — the single writer stays the applier.
 *
 * Every read is guarded: a table may be empty or absent (e.g. before
 * migrations run), so a failure to read any one section degrades to an empty
 * list rather than throwing — mirroring {@see AppContextBuilder}.
 */
class AppExporter
{
    /** Bumped when the export shape changes in a backwards-incompatible way. */
    public const VERSION = '1.0';

    public function __construct(private readonly Settings $settings) {}

    /**
     * The full app as an import-ready plan. The caller stamps any timestamp;
     * this stays side-effect-free so the same array can be written, diffed or
     * fed back to the applier unchanged.
     *
     * @return array{
     *   version:string,
     *   collections:list<array{key:string,name:string,has_timestamps:bool,has_soft_deletes:bool,fields:list<array{key:string,label:string,type:string,options:array<string,mixed>}>}>,
     *   states:list<array{key:string,type:string,value:mixed}>,
     *   functions:list<array{slug:string,name:string,runtime:string,body:?string}>,
     *   flows:list<array{slug:string,name:string,trigger_type:string,trigger_config:array<string,mixed>,definition:array<string,mixed>}>,
     *   watchers:list<array{name:string,source_type:string,source_key:string,event:?string,config:?array<string,mixed>,target_type:string,target_key:string,is_active:bool}>,
     *   pages:list<array{slug:string,title:string,kind:string,status:string,html:?string,css:?string,custom_css:?string,custom_js:?string,meta:?array<string,mixed>}>,
     *   settings:array{home_page:mixed,not_found_page:mixed,maintenance_page:mixed}
     * }
     */
    public function export(): array
    {
        return [
            'version' => self::VERSION,
            'collections' => $this->collections(),
            'states' => $this->states(),
            'functions' => $this->functions(),
            'flows' => $this->flows(),
            'watchers' => $this->watchers(),
            'pages' => $this->pages(),
            'settings' => $this->appSettings(),
        ];
    }

    /**
     * @return list<array{key:string,name:string,has_timestamps:bool,has_soft_deletes:bool,fields:list<array{key:string,label:string,type:string,options:array<string,mixed>}>}>
     */
    private function collections(): array
    {
        return $this->guard(function (): array {
            /** @var class-string<PbModel> $modelClass */
            $modelClass = config('ai-page-builder.models.model', PbModel::class);

            $out = [];
            foreach ($modelClass::query()->with('fields')->orderBy('key')->get() as $model) {
                /** @var PbModel $model */
                $fields = [];
                foreach ($model->fields as $field) {
                    /** @var PbField $field */
                    $fields[] = [
                        'key' => (string) $field->key,
                        'label' => (string) $field->label,
                        'type' => (string) $field->type,
                        'options' => is_array($field->options) ? $field->options : [],
                    ];
                }

                $out[] = [
                    'key' => (string) $model->key,
                    'name' => (string) $model->name,
                    'has_timestamps' => (bool) $model->has_timestamps,
                    'has_soft_deletes' => (bool) $model->has_soft_deletes,
                    'fields' => $fields,
                ];
            }

            return $out;
        });
    }

    /**
     * States carry their decoded native value + declared type, exactly the
     * pair VariableStore::set() expects on re-import.
     *
     * @return list<array{key:string,type:string,value:mixed}>
     */
    private function states(): array
    {
        return $this->guard(function (): array {
            /** @var class-string<Variable> $variableClass */
            $variableClass = config('ai-page-builder.models.variable', Variable::class);

            $out = [];
            foreach ($variableClass::query()->orderBy('key')->get() as $variable) {
                /** @var Variable $variable */
                $out[] = [
                    'key' => (string) $variable->key,
                    'type' => (string) $variable->type,
                    'value' => $variable->typedValue(),
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array{slug:string,name:string,runtime:string,body:?string}>
     */
    private function functions(): array
    {
        return $this->guard(function (): array {
            /** @var class-string<FlowFunction> $functionClass */
            $functionClass = config('ai-page-builder.models.flow_function', FlowFunction::class);

            $out = [];
            foreach ($functionClass::query()->orderBy('slug')->get() as $function) {
                /** @var FlowFunction $function */
                $out[] = [
                    'slug' => (string) $function->slug,
                    'name' => (string) $function->name,
                    'runtime' => (string) $function->runtime,
                    'body' => $function->body !== null ? (string) $function->body : null,
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array{slug:string,name:string,trigger_type:string,trigger_config:array<string,mixed>,definition:array<string,mixed>}>
     */
    private function flows(): array
    {
        return $this->guard(function (): array {
            /** @var class-string<Flow> $flowClass */
            $flowClass = config('ai-page-builder.models.flow', Flow::class);

            $out = [];
            foreach ($flowClass::query()->orderBy('slug')->get() as $flow) {
                /** @var Flow $flow */
                // trigger_config is cast to array on the model but not declared
                // as a @property, so read it via getAttribute to keep types clean.
                $triggerConfig = $flow->getAttribute('trigger_config');
                $out[] = [
                    'slug' => (string) $flow->slug,
                    'name' => (string) $flow->name,
                    'trigger_type' => (string) $flow->trigger_type,
                    'trigger_config' => is_array($triggerConfig) ? $triggerConfig : [],
                    'definition' => is_array($flow->definition) ? $flow->definition : [],
                ];
            }

            return $out;
        });
    }

    /**
     * Watchers are part of the app's automation and travel with it. Schedules
     * do NOT: their cron cadence is a deployment decision, not app content.
     *
     * @return list<array{name:string,source_type:string,source_key:string,event:?string,config:?array<string,mixed>,target_type:string,target_key:string,is_active:bool}>
     */
    private function watchers(): array
    {
        return $this->guard(function (): array {
            /** @var class-string<Watcher> $watcherClass */
            $watcherClass = config('ai-page-builder.models.watcher', Watcher::class);

            $out = [];
            foreach ($watcherClass::query()->orderBy('id')->get() as $watcher) {
                /** @var Watcher $watcher */
                $config = $watcher->getAttribute('config');
                $out[] = [
                    'name' => (string) $watcher->name,
                    'source_type' => (string) $watcher->source_type,
                    'source_key' => (string) $watcher->source_key,
                    'event' => $watcher->event,
                    'config' => is_array($config) ? $config : null,
                    'target_type' => (string) $watcher->target_type,
                    'target_key' => (string) $watcher->target_key,
                    'is_active' => (bool) $watcher->is_active,
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array{slug:string,title:string,kind:string,status:string,html:?string,css:?string,custom_css:?string,custom_js:?string,meta:?array<string,mixed>}>
     */
    private function pages(): array
    {
        return $this->guard(function (): array {
            /** @var class-string<Page> $pageClass */
            $pageClass = config('ai-page-builder.models.page', Page::class);

            $out = [];
            foreach ($pageClass::query()->orderBy('slug')->get() as $page) {
                /** @var Page $page */
                $out[] = [
                    'slug' => (string) $page->slug,
                    'title' => (string) $page->title,
                    'kind' => (string) $page->kind,
                    'status' => $page->status->value,
                    'html' => $page->html !== null ? (string) $page->html : null,
                    'css' => $page->css !== null ? (string) $page->css : null,
                    'custom_css' => $page->custom_css !== null ? (string) $page->custom_css : null,
                    'custom_js' => $page->custom_js !== null ? (string) $page->custom_js : null,
                    'meta' => is_array($page->meta) ? $page->meta : null,
                ];
            }

            return $out;
        });
    }

    /**
     * App-level settings the applier knows how to re-apply (home / 404 /
     * maintenance page pickers). Other settings — SMTP secrets especially —
     * are intentionally excluded: they are environment-specific and the
     * applier only reads these three keys.
     *
     * @return array{home_page:mixed,not_found_page:mixed,maintenance_page:mixed}
     */
    private function appSettings(): array
    {
        return [
            'home_page' => $this->settings->get('home_page'),
            'not_found_page' => $this->settings->get('not_found_page'),
            'maintenance_page' => $this->settings->get('maintenance_page'),
        ];
    }

    /**
     * Run a section reader, degrading to an empty list if the table is missing
     * or any read fails (the package may not be migrated in every environment).
     *
     * @template T
     *
     * @param  callable():list<T>  $reader
     * @return list<T>
     */
    private function guard(callable $reader): array
    {
        try {
            return $reader();
        } catch (Throwable) {
            return [];
        }
    }
}
