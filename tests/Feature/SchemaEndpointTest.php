<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\PbModel;
use Illuminate\Support\Facades\Schema;

/**
 * Covers the collection schema endpoint, the schema-driven PbModel::displayField()
 * accessor, and the display_field migration — the config/type-driven foundation the
 * configurable data table renders from (no magic field-name assumptions anywhere).
 */
beforeEach(function (): void {
    app(BuildPlanApplier::class)->apply([
        'collections' => [
            ['key' => 'categories', 'name' => 'Categories', 'fields' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'string', 'options' => ['required' => true]],
            ]],
            ['key' => 'products', 'name' => 'Products', 'fields' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'string'],
                ['key' => 'price', 'label' => 'Price', 'type' => 'decimal'],
                ['key' => 'in_stock', 'label' => 'In stock', 'type' => 'boolean'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['choices' => ['draft', 'live']]],
                ['key' => 'category', 'label' => 'Category', 'type' => 'relation', 'options' => ['relation_model' => 'categories']],
            ]],
        ],
    ]);

    $this->products = PbModel::query()->where('key', 'products')->firstOrFail();
    $this->categories = PbModel::query()->where('key', 'categories')->firstOrFail();
});

// ---------------------------------------------------------------------------
// Migration
// ---------------------------------------------------------------------------

it('adds the display_field column to the models table', function (): void {
    expect(Schema::hasColumn(PbModel::query()->getModel()->getTable(), 'display_field'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// PbModel::displayField()
// ---------------------------------------------------------------------------

it('defaults displayField to the first string/text field (never a magic name)', function (): void {
    // No display_field configured → first textual field in sort order ("label").
    expect($this->products->displayField())->toBe('label');
});

it('honours an explicitly configured display_field', function (): void {
    $this->products->update(['display_field' => 'status']);

    expect($this->products->fresh()->displayField())->toBe('status');
});

it('ignores a configured display_field that is not a real field', function (): void {
    $this->products->update(['display_field' => 'nonexistent']);

    // Falls back to type-based inference, not the bogus configured value.
    expect($this->products->fresh()->displayField())->toBe('label');
});

it('falls back to id when a collection has no string/text field', function (): void {
    app(BuildPlanApplier::class)->apply([
        'collections' => [
            ['key' => 'counters', 'name' => 'Counters', 'fields' => [
                ['key' => 'value', 'label' => 'Value', 'type' => 'integer'],
                ['key' => 'active', 'label' => 'Active', 'type' => 'boolean'],
            ]],
        ],
    ]);

    $counters = PbModel::query()->where('key', 'counters')->firstOrFail();

    expect($counters->displayField())->toBe('id');
});

// ---------------------------------------------------------------------------
// Schema endpoint
// ---------------------------------------------------------------------------

it('returns fields, display_field and relations for a collection', function (): void {
    $body = $this->getJson('/api/pb/products/schema')->assertOk()->json();

    expect($body)->toHaveKeys(['fields', 'display_field', 'relations']);

    // display_field is the type-inferred default.
    expect($body['display_field'])->toBe('label');

    // Every declared field is present with key/label/type.
    $byKey = collect($body['fields'])->keyBy('key');
    expect($byKey->keys()->all())->toContain('label', 'price', 'in_stock', 'status', 'category')
        ->and($byKey['price']['type'])->toBe('decimal')
        ->and($byKey['in_stock']['type'])->toBe('boolean')
        ->and($byKey['category']['type'])->toBe('relation');

    // Select field carries its choices as options; non-select fields have null options.
    expect($byKey['status']['options'])->toBe(['draft', 'live'])
        ->and($byKey['price']['options'])->toBeNull();

    // The relation exposes its target collection + that target's display field.
    expect($body['relations'])->toHaveKey('category')
        ->and($body['relations']['category']['collection'])->toBe('categories')
        ->and($body['relations']['category']['display_field'])->toBe('title');
});

it('reflects a configured display field in the schema endpoint', function (): void {
    $this->products->update(['display_field' => 'status']);

    $body = $this->getJson('/api/pb/products/schema')->assertOk()->json();

    expect($body['display_field'])->toBe('status');
});
