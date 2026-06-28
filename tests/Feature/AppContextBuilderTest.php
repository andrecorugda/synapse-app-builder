<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\AppContextBuilder;
use Andre\AiPageBuilder\Enums\PageStatus;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Variable;

it('returns empty lists when the app has nothing yet', function (): void {
    $ctx = (new AppContextBuilder)->build();

    expect($ctx['collections'])->toBe([])
        ->and($ctx['states'])->toBe([])
        ->and($ctx['pages'])->toBe([])
        ->and($ctx['functions'])->toBe([])
        ->and($ctx['flows'])->toBe([]);
});

it('always exposes the available component keys', function (): void {
    $ctx = (new AppContextBuilder)->build();

    expect($ctx['component_keys'])->toBeArray()
        ->and($ctx['component_keys'])->toContain('hero');
});

it('reflects a seeded collection with its fields', function (): void {
    $model = PbModel::create([
        'key' => 'tasks',
        'table_name' => PbModel::physicalTableName('tasks'),
        'name' => 'Tasks',
        'has_timestamps' => true,
        'has_soft_deletes' => false,
    ]);
    $model->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'string', 'sort' => 0]);
    $model->fields()->create(['key' => 'done', 'label' => 'Done', 'type' => 'boolean', 'sort' => 1]);

    $ctx = (new AppContextBuilder)->build();

    expect($ctx['collections'])->toHaveCount(1);

    $collection = $ctx['collections'][0];
    expect($collection['key'])->toBe('tasks')
        ->and($collection['name'])->toBe('Tasks')
        ->and($collection['fields'])->toHaveCount(2)
        ->and($collection['fields'][0]['key'])->toBe('title')
        ->and($collection['fields'][0]['type'])->toBe('string');
});

it('reflects a seeded state', function (): void {
    Variable::create(['key' => 'filter', 'type' => 'string', 'value' => 'all']);

    $ctx = (new AppContextBuilder)->build();

    expect($ctx['states'])->toHaveCount(1)
        ->and($ctx['states'][0]['key'])->toBe('filter')
        ->and($ctx['states'][0]['type'])->toBe('string');
});

it('reflects a seeded page', function (): void {
    Page::create([
        'title' => 'Home',
        'slug' => 'home',
        'status' => PageStatus::Published,
        'html' => '<section data-pb-block="hero"></section>',
    ]);

    $ctx = (new AppContextBuilder)->build();

    expect($ctx['pages'])->toHaveCount(1)
        ->and($ctx['pages'][0]['slug'])->toBe('home')
        ->and($ctx['pages'][0]['title'])->toBe('Home')
        ->and($ctx['pages'][0]['status'])->toBe('published');
});

it('renders a prompt string reflecting seeded state', function (): void {
    $model = PbModel::create([
        'key' => 'tasks',
        'table_name' => PbModel::physicalTableName('tasks'),
        'name' => 'Tasks',
        'has_timestamps' => true,
        'has_soft_deletes' => false,
    ]);
    $model->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'string', 'sort' => 0]);
    Variable::create(['key' => 'filter', 'type' => 'string', 'value' => 'all']);

    $string = (new AppContextBuilder)->toPromptString();

    expect($string)->toBeString()
        ->toContain('Current app context')
        ->toContain('`tasks`')
        ->toContain('title:string')
        ->toContain('`filter`');
});

it('renders "(none yet)" sections for an empty app', function (): void {
    $string = (new AppContextBuilder)->toPromptString();

    expect($string)
        ->toContain('## Collections')
        ->toContain('(none yet)');
});
