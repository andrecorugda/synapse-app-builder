<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Enums\PageStatus;
use Andre\AiPageBuilder\Support\PageDataMapper;

it('splits the builder composite into columns', function (): void {
    $out = PageDataMapper::split([
        'title' => 'Home',
        'status' => PageStatus::Draft->value,
        'builder' => ['project_data' => ['pages' => []], 'html' => '<p>hi</p>', 'css' => 'p{}'],
    ]);

    expect($out)->not->toHaveKey('builder')
        ->and($out['project_data'])->toEqual(['pages' => []])
        ->and($out['html'])->toBe('<p>hi</p>')
        ->and($out['css'])->toBe('p{}')
        ->and($out['published_at'])->toBeNull();
});

it('stamps published_at when publishing and clears it for drafts', function (): void {
    $published = PageDataMapper::split(['status' => PageStatus::Published->value, 'builder' => []]);
    expect($published['published_at'])->not->toBeNull();

    $draft = PageDataMapper::split(['status' => PageStatus::Draft->value, 'builder' => []]);
    expect($draft['published_at'])->toBeNull();
});

it('merges columns back into the builder composite for the form', function (): void {
    $out = PageDataMapper::merge([
        'project_data' => ['pages' => [1]],
        'html' => '<p>x</p>',
        'css' => '',
    ]);

    expect($out['builder'])->toEqual(['project_data' => ['pages' => [1]], 'html' => '<p>x</p>', 'css' => '']);
});
