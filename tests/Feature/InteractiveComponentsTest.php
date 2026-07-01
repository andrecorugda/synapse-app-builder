<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\HtmlSanitizer;
use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Blocks\SectionBlock;
use Andre\AiPageBuilder\Facades\PageBuilder;

/**
 * The interactive, data-driven UI toolkit — repeater, editable_grid,
 * context_menu, stepper, record_picker. These are the reusable building blocks
 * behind line-item apps (POS carts, invoices, order entry).
 *
 * They live in a NON-"Sections" category ('Interactive'), so they are
 * DRAG-ONLY: registered in the block manager and the capability catalogue, but
 * deliberately kept OUT of the AI page-generation vocabulary (keys()).
 *
 * Critically, although they are owner-authored (trusted), they are written to
 * survive the AI {@see HtmlSanitizer} unchanged: no inline @click / x-on: /
 * on*= that the sanitizer strips — all click/step wiring is delegated off
 * data-pb-* hooks in the published-page runtime. These tests lock that in.
 */
const PB_INTERACTIVE_KEYS = ['repeater', 'editable_grid', 'context_menu', 'stepper', 'record_picker'];

it('registers every interactive component under the non-Sections "Interactive" category', function (): void {
    $all = collect(BlockVocabulary::all())->pluck('key');
    $serialized = collect(BlockVocabulary::toArray())->pluck('key');

    foreach (PB_INTERACTIVE_KEYS as $key) {
        $block = BlockVocabulary::find($key);

        expect($block)->toBeInstanceOf(SectionBlock::class)
            ->and($block->category)->toBe('Interactive')
            ->and($block->category)->not->toBe(BlockVocabulary::SECTION_CATEGORY);

        expect($all)->toContain($key);
        expect($serialized)->toContain($key);
    }
});

it('keeps interactive components OUT of the AI section vocabulary (drag-only)', function (): void {
    $keys = BlockVocabulary::keys();

    foreach (PB_INTERACTIVE_KEYS as $key) {
        expect($keys)->not->toContain($key);
    }

    // Sanity: real sections are still in the vocabulary (no regression).
    expect($keys)->toContain('hero');
});

it('gives each interactive component an icon and a description', function (): void {
    foreach (PB_INTERACTIVE_KEYS as $key) {
        $block = BlockVocabulary::find($key);
        expect($block->icon)->toContain('<svg')
            ->and($block->description)->not->toBe('');
    }
});

it('survives HtmlSanitizer::sanitize() with the data-pb-block wrapper and declarative Alpine intact', function (): void {
    $sanitizer = new HtmlSanitizer;

    foreach (PB_INTERACTIVE_KEYS as $key) {
        $block = BlockVocabulary::find($key);
        $out = $sanitizer->sanitize($block->template);

        // The recognisable wrapper the runtime keys off must survive.
        expect($out)->toContain('data-pb-block="'.$key.'"');

        // Declarative Alpine that was present in the source must survive the round-trip.
        foreach (['x-data', 'x-for', 'x-model', 'x-show'] as $directive) {
            if (str_contains($block->template, $directive)) {
                expect($out)->toContain($directive);
            }
        }
    }
});

it('carries no sanitizer-stripped executable handlers in the saved markup', function (): void {
    $sanitizer = new HtmlSanitizer;

    foreach (PB_INTERACTIVE_KEYS as $key) {
        $template = BlockVocabulary::find($key)->template;

        // Source is already clean: no @click, x-on:, inline on*=, x-init, x-effect, x-html.
        expect($template)
            ->not->toMatch('/\s@[a-zA-Z]/')   // @click / @input shorthand
            ->not->toContain('x-on:')
            ->not->toContain('x-init')
            ->not->toContain('x-effect')
            ->not->toContain('x-html')
            ->not->toMatch('/\son[a-z]+\s*=/i'); // inline on*= handler

        // Because the source is already clean, sanitize() keeps the interactive
        // data-pb-* hooks so the delegated runtime can still wire clicks.
        expect($sanitizer->sanitize($template))->toContain('data-pb-');
    }
});

it('exposes each interactive component in the capability catalogue as kind "component"', function (): void {
    $catalogue = collect(PageBuilder::capabilities());

    foreach (PB_INTERACTIVE_KEYS as $key) {
        $entry = $catalogue->firstWhere('key', $key);

        expect($entry)->not->toBeNull();
        expect($entry['kind'])->toBe('component');
        expect($entry['category'])->toBe('Interactive');
    }
});

it('wires the expected data-pb-* delegation hooks per component', function (): void {
    $expected = [
        'repeater' => ['data-pb-state', 'data-pb-repeater-add', 'data-pb-repeater-remove', 'pbRepeater'],
        'editable_grid' => ['data-pb-state', 'data-pb-grid-add', 'data-pb-grid-remove', 'pbGrid'],
        'stepper' => ['data-pb-state', 'data-pb-step="1"', 'data-pb-step="-1"', 'pbStepper'],
        'context_menu' => ['data-pb-contextmenu', 'data-pb-context-toggle', 'pbContextMenu'],
        'record_picker' => ['data-pb-collection', 'data-pb-target', 'data-pb-pick', 'pbRecordPicker'],
    ];

    foreach ($expected as $key => $hooks) {
        $template = BlockVocabulary::find($key)->template;
        foreach ($hooks as $hook) {
            expect($template)->toContain($hook);
        }
    }
});
