<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Pages;

use Andre\AiPageBuilder\Models\PbModel;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Self-contained reference for the collections REST API, rendered inside the
 * panel. The endpoint catalogue and query-param reference are static; the
 * per-collection section is generated from the live PbModel + PbField metadata
 * so it always matches the app's actual collections (with copy-pasteable curl).
 */
class CollectionsApiDocs extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected string $view = 'ai-page-builder::filament.pages.collections-api-docs';

    public static function getNavigationLabel(): string
    {
        return 'API Docs';
    }

    public function getTitle(): string
    {
        return 'Collections API Docs';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-page-builder.filament.navigation_group', 'Content');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-page-builder.filament.navigation_sort', 10) + 7;
    }

    /**
     * The API base URL, e.g. https://app.test/api/pb.
     */
    public function baseUrl(): string
    {
        return rtrim(url((string) config('ai-page-builder.data.api_prefix', 'api/pb')), '/');
    }

    /**
     * Every collection with its fields, shaped for the docs view.
     *
     * @return Collection<int, array{
     *     key: string,
     *     name: string,
     *     description: ?string,
     *     fields: list<array{key: string, type: string, required: bool}>,
     * }>
     */
    public function collections(): Collection
    {
        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        return $modelClass::query()
            ->with('fields')
            ->orderBy('name')
            ->get()
            ->map(fn (PbModel $model): array => [
                'key' => $model->key,
                'name' => $model->name,
                'description' => $model->description,
                'fields' => $model->fields
                    ->map(fn ($field): array => [
                        'key' => $field->fieldType()->columnName($field->key),
                        'type' => $field->fieldType()->label(),
                        'required' => (bool) ($field->options['required'] ?? false),
                    ])
                    ->values()
                    ->all(),
            ]);
    }

    /**
     * A representative JSON body for a POST example: required fields plus the
     * first couple of optional ones, with type-appropriate placeholder values.
     *
     * @param  list<array{key: string, type: string, required: bool}>  $fields
     */
    public function exampleBody(array $fields): string
    {
        $payload = [];
        $optionalSeen = 0;

        foreach ($fields as $field) {
            if (! $field['required'] && $optionalSeen >= 2) {
                continue;
            }
            if (! $field['required']) {
                $optionalSeen++;
            }

            $payload[$field['key']] = $this->placeholderFor($field['type']);
        }

        if ($payload === []) {
            $payload = ['field' => 'value'];
        }

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function placeholderFor(string $typeLabel): mixed
    {
        return match (true) {
            str_contains($typeLabel, 'Integer'), str_contains($typeLabel, 'Relation') => 1,
            str_contains($typeLabel, 'Decimal') => 9.99,
            str_contains($typeLabel, 'Boolean') => true,
            str_contains($typeLabel, 'Date & time') => '2026-01-01 09:00:00',
            str_contains($typeLabel, 'Date') => '2026-01-01',
            str_contains($typeLabel, 'JSON') => ['key' => 'value'],
            default => 'example',
        };
    }
}
