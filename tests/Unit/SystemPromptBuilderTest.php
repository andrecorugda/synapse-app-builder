<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\SystemPromptBuilder;
use Andre\AiPageBuilder\Enums\FieldType;

it('builds a non-empty system prompt', function (): void {
    $prompt = (new SystemPromptBuilder)->build();

    expect($prompt)->toBeString()
        ->and(trim($prompt))->not->toBe('');
});

it('is deterministic — identical output on repeated builds', function (): void {
    $builder = new SystemPromptBuilder;

    expect($builder->build())->toBe($builder->build());
});

it('spells out the build-plan contract markers', function (): void {
    $prompt = (new SystemPromptBuilder)->build();

    expect($prompt)
        ->toContain('Build-plan contract')
        ->toContain('collections')
        ->toContain('states')
        ->toContain('functions')
        ->toContain('flows')
        ->toContain('pages')
        ->toContain('data-pb-block');
});

it('lists known component keys from the vocabulary', function (): void {
    $prompt = (new SystemPromptBuilder)->build();

    // A few representative keys across categories.
    expect($prompt)
        ->toContain('`hero`')
        ->toContain('`modal`')
        ->toContain('`data_table`')
        ->toContain('`form`');
});

it('declares the data category as data-bound and forms for input', function (): void {
    $prompt = (new SystemPromptBuilder)->build();

    expect($prompt)
        ->toContain('Data')
        ->toContain('Forms')
        ->toContain("pbTable('");
});

it('lists every field type with its value', function (): void {
    $prompt = (new SystemPromptBuilder)->build();

    foreach (FieldType::cases() as $type) {
        expect($prompt)->toContain("`{$type->value}`");
    }
});

it('lists the registered flow node types with config hints', function (): void {
    $prompt = (new SystemPromptBuilder)->build();

    expect($prompt)
        ->toContain('`trigger`')
        ->toContain('`record`')
        ->toContain('`condition`')
        ->toContain('`ai_invoke`')
        ->toContain('`result`')
        ->toContain('next_true');
});

it('states the hard rules including the no-executable-directive rule', function (): void {
    $prompt = (new SystemPromptBuilder)->build();

    expect($prompt)
        ->toContain('```json')
        ->toContain('@click')
        ->toContain('x-text');
});
