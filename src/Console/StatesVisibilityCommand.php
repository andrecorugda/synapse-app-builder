<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;
use Andre\AiPageBuilder\Models\Variable;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Andre\AiPageBuilder\Services\PageRenderer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * List and change which States are client-visible (sent to rendered pages).
 *
 * Since 2.0 a State is server-only unless marked public. After upgrading,
 * `--referenced` marks public every State a page or partial binds to
 * (`$store.app.<key>`, `data-pb-state` / `data-pb-target="<key>"`) so existing
 * pages keep showing them; review the list first by running the command with no
 * option, and never mark a secret public — move it to a Credential instead.
 */
class StatesVisibilityCommand extends Command
{
    protected $signature = 'ai-page-builder:states
        {--public= : Comma-separated State keys to make client-visible}
        {--private= : Comma-separated State keys to make server-only}
        {--referenced : Make every State referenced by a page or partial client-visible}';

    protected $description = 'List States with their visibility, or mark States client-visible / server-only.';

    public function handle(VariableStore $store, PageRenderer $renderer): int
    {
        $referenced = $this->referencedKeys();
        $changed = 0;

        if (is_string($this->option('private')) && $this->option('private') !== '') {
            $changed += $store->setPublic($this->keys((string) $this->option('private')), false);
        }

        $public = is_string($this->option('public')) ? $this->keys((string) $this->option('public')) : [];
        if ($this->option('referenced')) {
            $public = array_merge($public, $referenced);
        }
        if ($public !== []) {
            $changed += $store->setPublic(array_values(array_unique($public)), true);
        }

        if ($changed > 0) {
            // Pages embed the public States: drop cached renders.
            $renderer->flushAll();
            $this->info("Updated {$changed} State(s).");
        }

        /** @var class-string<Variable> $variableClass */
        $variableClass = config('ai-page-builder.models.variable', Variable::class);

        $rows = $variableClass::query()->orderBy('key')->get()->map(fn (Variable $v): array => [
            $v->key,
            $v->type,
            $v->getAttribute('is_public') ? 'public' : 'server-only',
            in_array($v->key, $referenced, true) ? 'yes' : '',
        ])->all();

        $this->table(['State', 'Type', 'Visibility', 'Used by a page'], $rows);

        return self::SUCCESS;
    }

    /**
     * Keys of States referenced from page / partial markup or custom JS.
     *
     * @return list<string>
     */
    private function referencedKeys(): array
    {
        /** @var list<class-string<Model>> $classes */
        $classes = [config('ai-page-builder.models.page', Page::class), config('ai-page-builder.models.partial', Partial::class)];

        $sources = [];
        foreach ($classes as $class) {
            foreach ($class::query()->get() as $row) {
                $sources[] = (string) $row->getAttribute('html')."\n".(string) $row->getAttribute('custom_js');
            }
        }

        $keys = [];
        foreach ($sources as $source) {
            preg_match_all('/\$store\.app\.([A-Za-z0-9_]+)|\$store\.app\[[\'"]([A-Za-z0-9_]+)[\'"]\]|data-pb-(?:state|target)="([A-Za-z0-9_]+)"/', $source, $m);
            foreach ([...$m[1], ...$m[2], ...$m[3]] as $key) {
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        /** @var class-string<Variable> $variableClass */
        $variableClass = config('ai-page-builder.models.variable', Variable::class);

        return array_values(array_intersect(array_keys($keys), $variableClass::query()->pluck('key')->all()));
    }

    /** @return list<string> */
    private function keys(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }
}
