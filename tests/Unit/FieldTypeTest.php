<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Enums\FieldType;

it('maps relation fields to a {key}_id column and others verbatim', function (): void {
    expect(FieldType::Relation->columnName('manager'))->toBe('manager_id')
        ->and(FieldType::String->columnName('name'))->toBe('name');
});

it('exposes the right eloquent cast per type', function (): void {
    expect(FieldType::Integer->cast())->toBe('integer')
        ->and(FieldType::Relation->cast())->toBe('integer')
        ->and(FieldType::Decimal->cast())->toBe('float')
        ->and(FieldType::Boolean->cast())->toBe('boolean')
        ->and(FieldType::Json->cast())->toBe('array')
        ->and(FieldType::DateTime->cast())->toBe('datetime')
        ->and(FieldType::String->cast())->toBeNull();
});

it('builds required/nullable + type validation rules', function (): void {
    expect(FieldType::String->validationRules(['required' => true]))
        ->toBe(['required', 'string', 'max:255']);

    expect(FieldType::Integer->validationRules())
        ->toBe(['nullable', 'integer']);
});

it('adds an in: rule for select choices', function (): void {
    $rules = FieldType::Select->validationRules(['choices' => ['open', 'won', 'lost']]);

    expect($rules)->toContain('in:open,won,lost');
});

it('honours a custom string length in rules', function (): void {
    expect(FieldType::String->validationRules(['length' => 50]))
        ->toContain('max:50');
});
