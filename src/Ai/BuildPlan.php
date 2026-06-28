<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Ai;

/**
 * A typed value object over the five build-plan sections. This is the canonical
 * shape the prompt-builder describes to the AI and the applier writes through —
 * a thin, immutable wrapper so consumers read sections instead of poking at raw
 * array keys. Unknown top-level keys are ignored; missing sections default to
 * an empty list.
 *
 * @phpstan-type PlanList list<array<string,mixed>>
 */
final readonly class BuildPlan
{
    /**
     * @param  PlanList  $collections
     * @param  PlanList  $states
     * @param  PlanList  $functions
     * @param  PlanList  $flows
     * @param  PlanList  $pages
     * @param  array<string,mixed>  $settings  App-level config (e.g. home_page).
     */
    public function __construct(
        public array $collections = [],
        public array $states = [],
        public array $functions = [],
        public array $flows = [],
        public array $pages = [],
        public array $settings = [],
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     */
    public static function fromArray(array $plan): self
    {
        return new self(
            collections: self::list($plan, 'collections'),
            states: self::list($plan, 'states'),
            functions: self::list($plan, 'functions'),
            flows: self::list($plan, 'flows'),
            pages: self::list($plan, 'pages'),
            settings: is_array($plan['settings'] ?? null) ? $plan['settings'] : [],
        );
    }

    /** @return PlanList */
    public function collections(): array
    {
        return $this->collections;
    }

    /** @return PlanList */
    public function states(): array
    {
        return $this->states;
    }

    /** @return PlanList */
    public function functions(): array
    {
        return $this->functions;
    }

    /** @return PlanList */
    public function flows(): array
    {
        return $this->flows;
    }

    /** @return PlanList */
    public function pages(): array
    {
        return $this->pages;
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'collections' => $this->collections,
            'states' => $this->states,
            'functions' => $this->functions,
            'flows' => $this->flows,
            'pages' => $this->pages,
            'settings' => $this->settings,
        ];
    }

    /**
     * Pull a top-level section as a clean list of associative arrays. Anything
     * that isn't a list of arrays collapses to an empty list so downstream code
     * can iterate without guarding every element.
     *
     * @param  array<string,mixed>  $plan
     * @return list<array<string,mixed>>
     */
    private static function list(array $plan, string $key): array
    {
        $value = $plan[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string,mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }
}
