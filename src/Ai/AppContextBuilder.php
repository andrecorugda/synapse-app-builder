<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Ai;

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Variable;

/**
 * Builds the per-request DYNAMIC app context for Synapse — App Builder.
 *
 * Where SystemPromptBuilder describes the engine in the abstract (what blocks,
 * field types and nodes exist), this describes THIS app right now: its existing
 * collections, states, pages, functions and flows. Injected alongside the user
 * request so the model builds against reality — extending what's there and
 * referencing real keys rather than inventing them.
 *
 * Every read is guarded: the package tables may be empty or absent (e.g. before
 * migrations run), so a failure to read any one section degrades to an empty
 * list rather than throwing.
 */
final class AppContextBuilder
{
    /**
     * @return array{
     *   collections: list<array{key:string,name:string,fields:list<array{key:string,label:string,type:string}>}>,
     *   states: list<array{key:string,type:string}>,
     *   pages: list<array{slug:string,title:string,status:string}>,
     *   functions: list<array{slug:string,name:string,runtime:string}>,
     *   flows: list<array{slug:string,name:string,trigger_type:string}>,
     *   component_keys: list<string>
     * }
     */
    public function build(): array
    {
        return [
            'collections' => $this->collections(),
            'states' => $this->states(),
            'pages' => $this->pages(),
            'functions' => $this->functions(),
            'flows' => $this->flows(),
            'component_keys' => BlockVocabulary::keys(),
        ];
    }

    /**
     * A compact, human-readable rendering of build() for embedding in a prompt.
     */
    public function toPromptString(): string
    {
        $ctx = $this->build();

        $lines = ['# Current app context'];

        $lines[] = '';
        $lines[] = '## Collections';
        if ($ctx['collections'] === []) {
            $lines[] = '(none yet)';
        } else {
            foreach ($ctx['collections'] as $collection) {
                $fields = array_map(
                    static fn (array $f): string => "{$f['key']}:{$f['type']}",
                    $collection['fields']
                );
                $fieldList = $fields === [] ? 'no fields' : implode(', ', $fields);
                $lines[] = "- `{$collection['key']}` ({$collection['name']}) — {$fieldList}";
            }
        }

        $lines[] = '';
        $lines[] = '## States';
        if ($ctx['states'] === []) {
            $lines[] = '(none yet)';
        } else {
            foreach ($ctx['states'] as $state) {
                $lines[] = "- `{$state['key']}` ({$state['type']})";
            }
        }

        $lines[] = '';
        $lines[] = '## Pages';
        if ($ctx['pages'] === []) {
            $lines[] = '(none yet)';
        } else {
            foreach ($ctx['pages'] as $page) {
                $lines[] = "- `{$page['slug']}` (\"{$page['title']}\", {$page['status']})";
            }
        }

        $lines[] = '';
        $lines[] = '## Functions';
        if ($ctx['functions'] === []) {
            $lines[] = '(none yet)';
        } else {
            foreach ($ctx['functions'] as $function) {
                $lines[] = "- `{$function['slug']}` ({$function['name']}, {$function['runtime']})";
            }
        }

        $lines[] = '';
        $lines[] = '## Flows';
        if ($ctx['flows'] === []) {
            $lines[] = '(none yet)';
        } else {
            foreach ($ctx['flows'] as $flow) {
                $lines[] = "- `{$flow['slug']}` ({$flow['name']}, trigger: {$flow['trigger_type']})";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<array{key:string,name:string,fields:list<array{key:string,label:string,type:string}>}>
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
                    ];
                }

                $out[] = [
                    'key' => (string) $model->key,
                    'name' => (string) $model->name,
                    'fields' => $fields,
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array{key:string,type:string}>
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
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array{slug:string,title:string,status:string}>
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
                    'status' => $page->status->value,
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array{slug:string,name:string,runtime:string}>
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
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array{slug:string,name:string,trigger_type:string}>
     */
    private function flows(): array
    {
        return $this->guard(function (): array {
            /** @var class-string<Flow> $flowClass */
            $flowClass = config('ai-page-builder.models.flow', Flow::class);

            $out = [];
            foreach ($flowClass::query()->orderBy('slug')->get() as $flow) {
                /** @var Flow $flow */
                $out[] = [
                    'slug' => (string) $flow->slug,
                    'name' => (string) $flow->name,
                    'trigger_type' => (string) $flow->trigger_type,
                ];
            }

            return $out;
        });
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
        } catch (\Throwable) {
            return [];
        }
    }
}
