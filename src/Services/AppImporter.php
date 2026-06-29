<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Ai\BuildPlanApplier;

/**
 * Import a previously-exported app (or any plan-shaped document) by replaying
 * it through {@see BuildPlanApplier} — the single writer for every AI / import
 * path. This is a thin wrapper: it adds no writes of its own, so an import
 * behaves exactly like an AI build (idempotent upsert by key/slug, sanitized
 * page html, per-item error reporting).
 *
 * The top-level `version` key produced by {@see AppExporter} is ignored by the
 * applier (it reads only the five sections + settings), so an export array can
 * be passed straight through.
 */
class AppImporter
{
    public function __construct(private readonly BuildPlanApplier $applier) {}

    /**
     * @param  array<string,mixed>  $plan
     * @return array{created:array{collections:list<string>,states:list<string>,functions:list<string>,flows:list<string>,pages:list<string>,settings:list<string>},errors:list<string>}
     */
    public function import(array $plan, bool $dryRun = false): array
    {
        return $this->applier->apply($plan, $dryRun);
    }
}
