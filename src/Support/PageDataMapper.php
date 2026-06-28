<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Support;

use Andre\AiPageBuilder\Enums\PageStatus;

/**
 * Maps between the GrapesJsField's composite `builder` form state and the
 * Page model's discrete project_data / html / css columns, and keeps
 * published_at consistent with status.
 */
final class PageDataMapper
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

        $status = $data['status'] ?? PageStatus::Draft->value;
        if ($status === PageStatus::Published->value) {
            $data['published_at'] = $data['published_at'] ?? now();
        } else {
            $data['published_at'] = null;
        }

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
