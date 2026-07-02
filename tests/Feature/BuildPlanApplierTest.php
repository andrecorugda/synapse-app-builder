<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Ai\BuildPlanValidator;
use Andre\AiPageBuilder\Ai\HtmlSanitizer;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\FlowRun;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Models\Watcher;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Support\Facades\Schema;

/**
 * A representative plan exercising all five sections, including AI page html
 * with both an executable directive (must be stripped) and a declarative one
 * (must survive).
 *
 * @return array<string,mixed>
 */
function samplePlan(): array
{
    return [
        'collections' => [[
            'key' => 'leads',
            'name' => 'Leads',
            'has_timestamps' => true,
            'has_soft_deletes' => false,
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
                ['key' => 'email', 'label' => 'Email', 'type' => 'string', 'options' => ['unique' => true]],
            ],
            'seed' => [
                ['name' => 'Acme', 'email' => 'a@acme.com'],
            ],
        ]],
        'states' => [
            ['key' => 'cart_total', 'type' => 'number', 'value' => 0],
        ],
        'functions' => [
            ['slug' => 'markup', 'name' => 'Markup', 'runtime' => 'expression', 'body' => 'args["price"] * 1.2'],
        ],
        'flows' => [[
            'slug' => 'on-lead',
            'name' => 'On Lead',
            'trigger_type' => 'collection',
            'trigger_config' => ['collection' => 'leads', 'events' => ['created']],
            'definition' => ['start' => 'n1', 'nodes' => ['n1' => ['type' => 'trigger', 'config' => [], 'next' => []]]],
        ]],
        'pages' => [[
            'slug' => 'home',
            'title' => 'Home',
            'status' => 'draft',
            'html' => '<section data-pb-block="hero"><h1 x-text="title">Hi</h1>'
                .'<button @click="boom()">x</button><script>alert(1)</script></section>',
            'css' => '',
        ]],
    ];
}

// ---------------------------------------------------------------------------
// Applier
// ---------------------------------------------------------------------------

it('applies a full build plan through the data services', function (): void {
    $summary = app(BuildPlanApplier::class)->apply(samplePlan());

    expect($summary['errors'])->toBe([])
        ->and($summary['created']['collections'])->toBe(['leads'])
        ->and($summary['created']['states'])->toBe(['cart_total'])
        ->and($summary['created']['functions'])->toBe(['markup'])
        ->and($summary['created']['flows'])->toBe(['on-lead'])
        ->and($summary['created']['pages'])->toBe(['home']);

    // Collection table + columns exist.
    expect(Schema::hasTable('pb_leads'))->toBeTrue()
        ->and(Schema::hasColumns('pb_leads', ['id', 'name', 'email', 'created_at', 'updated_at']))->toBeTrue();

    // Seed row exists.
    $model = PbModel::query()->where('key', 'leads')->firstOrFail();
    $rows = Record::for($model)->newQuery()->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Acme');

    // State, function, flow, page rows exist.
    expect(app(VariableStore::class)->get('cart_total'))->toBe(0)
        ->and(FlowFunction::query()->where('slug', 'markup')->exists())->toBeTrue()
        ->and(Flow::query()->where('slug', 'on-lead')->exists())->toBeTrue();

    $page = Page::query()->where('slug', 'home')->firstOrFail();
    expect($page->status->value)->toBe('draft');
});

it('sanitizes ai page html: strips script and @click, keeps x-text and data-pb-block', function (): void {
    app(BuildPlanApplier::class)->apply(samplePlan());

    $html = (string) Page::query()->where('slug', 'home')->firstOrFail()->html;

    expect($html)
        ->not->toContain('<script')
        ->not->toContain('@click')
        ->not->toContain('boom()')
        ->toContain('x-text')
        ->toContain('data-pb-block');
});

it('is idempotent — re-applying the same plan does not duplicate metadata', function (): void {
    $applier = app(BuildPlanApplier::class);
    $applier->apply(samplePlan());
    $second = $applier->apply(samplePlan());

    // Metadata upserts by key/slug, so the second apply creates no duplicates.
    expect(PbModel::query()->where('key', 'leads')->count())->toBe(1)
        ->and(Flow::query()->where('slug', 'on-lead')->count())->toBe(1)
        ->and(Page::query()->where('slug', 'home')->count())->toBe(1);

    // The seed row's unique email blocks a duplicate insert; the applier reports
    // it per-item (best-effort) rather than aborting, so the table stays at 1.
    expect(Record::for(PbModel::query()->where('key', 'leads')->firstOrFail())->newQuery()->count())->toBe(1)
        ->and($second['errors'])->not->toBeEmpty();
});

it('materializes watchers for collection flows — and the automation fires', function (): void {
    app(BuildPlanApplier::class)->apply(samplePlan());

    // One watcher per (collection, event) → flow.
    $watcher = Watcher::query()->where('target_key', 'on-lead')->first();
    expect($watcher)->not->toBeNull()
        ->and($watcher->source_type)->toBe('collection')
        ->and($watcher->source_key)->toBe('leads')
        ->and($watcher->event)->toBe('created')
        ->and($watcher->is_active)->toBeTrue();

    // Re-applying upserts, never duplicates.
    app(BuildPlanApplier::class)->apply(samplePlan());
    expect(Watcher::query()->where('target_key', 'on-lead')->count())->toBe(1);

    // And a record write actually runs the generated flow (dispatch is
    // watcher-driven now — this is the regression the materialization guards).
    $model = PbModel::query()->where('key', 'leads')->firstOrFail();
    Record::for($model)->newQuery()->create(['name' => 'Beta', 'email' => 'b@beta.com']);

    expect(FlowRun::query()->where('flow_slug_snapshot', 'on-lead')->count())->toBe(1);
});

it('applies an explicit watchers section (export/import path)', function (): void {
    app(BuildPlanApplier::class)->apply(samplePlan());

    $summary = app(BuildPlanApplier::class)->apply([
        'watchers' => [[
            'name' => 'lead deleted → on-lead',
            'source_type' => 'collection',
            'source_key' => 'leads',
            'event' => 'deleted',
            'target_type' => 'flow',
            'target_key' => 'on-lead',
            'is_active' => true,
        ]],
    ]);

    expect($summary['errors'])->toBe([])
        ->and($summary['created']['watchers'])->toBe(['collection:leads deleted → on-lead'])
        ->and(Watcher::query()->where('event', 'deleted')->where('target_key', 'on-lead')->exists())->toBeTrue();
});

it('flags a structurally broken watcher', function (): void {
    $errors = app(BuildPlanValidator::class)->validate([
        'watchers' => [[
            'source_type' => 'weird',
            'event' => 'sometimes',
            'target_type' => 'robot',
        ]],
    ]);

    expect($errors)->toContain("watchers[0]: source_type 'weird' must be collection or state.")
        ->and($errors)->toContain("watchers[0]: target_type 'robot' must be flow or function.")
        ->and($errors)->toContain("watchers[0]: event 'sometimes' must be created, updated, deleted or changed.")
        ->and($errors)->toContain('watchers[0]: source_key is required.')
        ->and($errors)->toContain('watchers[0]: target_key is required.');
});

it('dry-run reports what would be created without writing', function (): void {
    $summary = app(BuildPlanApplier::class)->apply(samplePlan(), dryRun: true);

    expect($summary['created']['collections'])->toBe(['leads'])
        ->and($summary['created']['pages'])->toBe(['home'])
        ->and(Schema::hasTable('pb_leads'))->toBeFalse()
        ->and(PbModel::query()->where('key', 'leads')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Validator
// ---------------------------------------------------------------------------

it('passes a good plan with no errors', function (): void {
    expect(app(BuildPlanValidator::class)->validate(samplePlan()))->toBe([]);
});

it('flags a bad field type', function (): void {
    $plan = samplePlan();
    $plan['collections'][0]['fields'][0]['type'] = 'banana';

    $errors = app(BuildPlanValidator::class)->validate($plan);
    expect($errors)->not->toBeEmpty()
        ->and(implode("\n", $errors))->toContain('banana');
});

it('flags a bad flow node type', function (): void {
    $plan = samplePlan();
    $plan['flows'][0]['definition']['nodes']['n1']['type'] = 'nonsense';

    $errors = app(BuildPlanValidator::class)->validate($plan);
    expect(implode("\n", $errors))->toContain('nonsense');
});

it('flags a bad slug', function (): void {
    $plan = samplePlan();
    $plan['collections'][0]['key'] = 'Not A Slug!';

    $errors = app(BuildPlanValidator::class)->validate($plan);
    expect(implode("\n", $errors))->toContain('not a valid slug');
});

it('flags a bad state type and function runtime', function (): void {
    $plan = samplePlan();
    $plan['states'][0]['type'] = 'datetime';
    $plan['functions'][0]['runtime'] = 'ruby';

    $errors = app(BuildPlanValidator::class)->validate($plan);
    $joined = implode("\n", $errors);
    expect($joined)->toContain('datetime')
        ->and($joined)->toContain('ruby');
});

it('warns on an unknown data-pb-block but does not block known ones', function (): void {
    $plan = samplePlan();
    $plan['pages'][0]['html'] = '<section data-pb-block="made_up">x</section>';

    $errors = app(BuildPlanValidator::class)->validate($plan);
    expect(implode("\n", $errors))->toContain('made_up');
});

// ---------------------------------------------------------------------------
// HtmlSanitizer (direct)
// ---------------------------------------------------------------------------

it('strips executable alpine but keeps declarative bindings', function (): void {
    $html = '<div x-data="{open:false}" x-init="boom()" @click="open=true" :style="open?\'a\':\'b\'" '
        .'x-show="open" x-html="evil" onclick="hack()" data-pb-block="card">'
        .'<a href="javascript:alert(1)">bad</a><a href="/ok">ok</a></div>';

    $clean = app(HtmlSanitizer::class)->sanitize($html);

    expect($clean)
        ->toContain('x-data')
        ->toContain(':style')
        ->toContain('x-show')
        ->toContain('data-pb-block')
        ->not->toContain('x-init')
        ->not->toContain('@click')
        ->not->toContain('x-html')
        ->not->toContain('onclick')
        ->not->toContain('javascript:');
});

// --- email templates + home page (wire-up) ----------------------------------

it('applies page kind and the home_page setting', function (): void {
    $plan = [
        'pages' => [
            ['slug' => 'home', 'title' => 'Home', 'kind' => 'page', 'status' => 'published', 'html' => '<h1>Hi</h1>'],
            ['slug' => 'welcome-email', 'title' => 'Welcome', 'kind' => 'email', 'status' => 'draft', 'html' => '<p>Hi {{ input.record.name }}</p>'],
        ],
        'settings' => ['home_page' => 'home'],
    ];

    $summary = app(BuildPlanApplier::class)->apply($plan);

    expect(Page::query()->where('slug', 'home')->value('kind'))->toBe('page')
        ->and(Page::query()->where('slug', 'welcome-email')->value('kind'))->toBe('email')
        ->and(app(Settings::class)->get('home_page'))->toBe('home')
        ->and($summary['created']['settings'])->toContain('home_page=home');
});

it('defaults page kind to page when omitted', function (): void {
    app(BuildPlanApplier::class)->apply(['pages' => [['slug' => 'plain', 'title' => 'Plain', 'html' => '<p>x</p>']]]);

    expect(Page::query()->where('slug', 'plain')->value('kind'))->toBe('page');
});

it('validates page kind and the home_page setting', function (): void {
    $v = app(BuildPlanValidator::class);

    expect($v->validate(['pages' => [['slug' => 'p', 'kind' => 'page']]]))->toBe([]);

    // bad kind
    expect($v->validate(['pages' => [['slug' => 'p', 'kind' => 'nope']]]))
        ->toContain("pages[0]: kind 'nope' must be 'page' or 'email'.");

    // home_page pointing at an email template is a hard error
    $errs = $v->validate([
        'pages' => [['slug' => 'mail', 'kind' => 'email']],
        'settings' => ['home_page' => 'mail'],
    ]);
    expect($errs)->toContain("settings.home_page: 'mail' is an email template (kind=email) and cannot be the home page.");

    // home_page not in the plan is a warning, not a blocker
    $warn = $v->validate(['settings' => ['home_page' => 'existing-elsewhere']]);
    expect($warn)->toContain("settings.home_page (warning): 'existing-elsewhere' is not a page in this plan — ensure it already exists.");
});

it('infers kind=email for a page used as a send_email template', function (): void {
    $plan = [
        'pages' => [
            // NOTE: kind omitted on purpose — the applier must infer it.
            ['slug' => 'welcome-mail', 'title' => 'Welcome', 'status' => 'draft', 'html' => '<p>Hi {{ input.record.name }}</p>'],
        ],
        'flows' => [[
            'slug' => 'on-signup', 'name' => 'On signup', 'trigger_type' => 'collection',
            'definition' => ['start' => 't', 'nodes' => [
                't' => ['type' => 'trigger', 'next' => ['m']],
                'm' => ['type' => 'send_email', 'config' => ['to' => 'x@y.com', 'template' => 'welcome-mail']],
            ]],
        ]],
    ];

    app(BuildPlanApplier::class)->apply($plan);

    expect(Page::query()->where('slug', 'welcome-mail')->value('kind'))->toBe('email');
});
