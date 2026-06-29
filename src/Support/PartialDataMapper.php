<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Support;

/**
 * Maps between the GrapesJsField's composite `builder` form state and the
 * Partial model's discrete project_data / html / css columns. Mirrors
 * PageDataMapper, minus the status/published_at handling partials don't have —
 * a partial is always-live markup, not a publishable document.
 */
final class PartialDataMapper
{
    /**
     * Form data → DB columns (create/save).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function split(array $data): array
    {
        $builder = is_array($data['builder'] ?? null) ? $data['builder'] : [];

        $data['project_data'] = $builder['project_data'] ?? [];
        $data['html'] = (string) ($builder['html'] ?? '');
        $data['css'] = (string) ($builder['css'] ?? '');
        unset($data['builder']);

        return $data;
    }

    /**
     * DB columns → form data (fill).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function merge(array $data): array
    {
        $data['builder'] = [
            'project_data' => $data['project_data'] ?? [],
            'html' => $data['html'] ?? '',
            'css' => $data['css'] ?? '',
        ];

        return $data;
    }
}
