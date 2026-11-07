<?php

declare(strict_types=1);

/*
 * The finer abilities (synapse.manage-*) narrow access and never fall back to
 * "allow" behind a host's back (adversarial review A3, R1).
 *
 * An undefined finer ability follows the admin decision on a plain
 * Gate::define host, but denies when the host runs a permission system
 * (spatie/laravel-permission, or any Gate::before hook that grants
 * synapse.admin), when `filament.fine_abilities_fallback` is `deny`, and it
 * follows the plugin ->authorize() callback on whatever panel is deciding.
 */

use Andre\AiPageBuilder\Filament\AiPageBuilderPlugin;
use Andre\AiPageBuilder\Support\SynapseAccess;
use Andre\AiPageBuilder\Tests\Fixtures\FakePermissionRegistrar;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    bootSynapsePanel();
    FakePermissionRegistrar::$names = [];
});

afterEach(function (): void {
    FakePermissionRegistrar::$names = [];
});

it('follows synapse.admin for every finer ability on a plain Gate::define host', function (): void {
    $this->actingAs(synapseAdmin());

    foreach (SynapseAccess::FINE_SCOPES as $scope) {
        expect(SynapseAccess::check($scope))->toBeTrue("{$scope} should follow synapse.admin");
    }
});

it('denies a finer ability a Spatie-style Gate::before hook does not grant', function (): void {
    // What spatie/laravel-permission registers: allow if the user holds a
    // permission with the ability's name, otherwise null.
    $permissions = ['synapse.admin', 'synapse.manage-code'];
    Gate::before(fn ($user, string $ability): ?bool => in_array($ability, $permissions, true) ? true : null);

    $this->actingAs(panelStaff());

    expect(SynapseAccess::check(SynapseAccess::ADMIN))->toBeTrue()
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_CODE))->toBeTrue()
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_SECRETS))->toBeFalse()
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_USERS))->toBeFalse()
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_SETTINGS))->toBeFalse()
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_DATA_SOURCES))->toBeFalse();
});

it('denies a finer ability registered as a spatie permission nobody holds', function (): void {
    if (! class_exists('Spatie\\Permission\\PermissionRegistrar')) {
        class_alias(FakePermissionRegistrar::class, 'Spatie\\Permission\\PermissionRegistrar');
    }
    FakePermissionRegistrar::$names = ['synapse.manage-secrets'];
    app()->instance('Spatie\\Permission\\PermissionRegistrar', new FakePermissionRegistrar);

    $this->actingAs(synapseAdmin());

    expect(SynapseAccess::check(SynapseAccess::ADMIN))->toBeTrue()
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_SECRETS))->toBeFalse()
        // Not registered as a permission: still follows synapse.admin.
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_USERS))->toBeTrue();
});

it('denies every undefined finer ability when fine_abilities_fallback is deny', function (): void {
    config()->set('ai-page-builder.filament.fine_abilities_fallback', 'deny');
    Gate::define('synapse.manage-users', fn ($user): bool => (bool) $user->is_synapse_admin);

    $this->actingAs(synapseAdmin());

    expect(SynapseAccess::check(SynapseAccess::ADMIN))->toBeTrue()
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_SECRETS))->toBeFalse()
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_CODE))->toBeFalse()
        // Defined by the host: its own definition decides.
        ->and(SynapseAccess::check(SynapseAccess::MANAGE_USERS))->toBeTrue();
});

it('fails closed on an unknown fine_abilities_fallback value', function (): void {
    config()->set('ai-page-builder.filament.fine_abilities_fallback', 'allow');
    $this->actingAs(synapseAdmin());

    expect(SynapseAccess::check(SynapseAccess::MANAGE_SECRETS))->toBeFalse();
});

it('a finer ability can never widen access beyond synapse.admin', function (): void {
    Gate::define('synapse.manage-secrets', fn (): bool => true);
    $this->actingAs(panelStaff());

    expect(SynapseAccess::check(SynapseAccess::MANAGE_SECRETS))->toBeFalse();
});

it('lets a builder the plugin ->authorize() callback admits on a non-default panel use manage-code endpoints', function (): void {
    // Synapse lives on a second, non-default panel, granted by the plugin callback only.
    $panel = Panel::make()->id('builder')->path('builder')
        ->plugin(AiPageBuilderPlugin::make()->authorize(fn ($user): bool => (bool) $user->is_synapse_admin));
    app(PanelRegistry::class)->register($panel);
    config()->set('ai-page-builder.filament.panel', 'builder');
    config()->set('ai-page-builder.flow.allow_php_functions', true);

    $this->actingAs(panelStaff(['is_synapse_admin' => true]));

    $this->postJson('/ai-page-builder/lint/php', ['code' => 'echo 1;'])->assertOk();
    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => ['functions' => [[
        'slug' => 'php-fn', 'name' => 'php-fn', 'runtime' => 'php', 'body' => 'return 1;',
    ]]]])->assertOk();

    // ... and the callback still refuses everyone else.
    $this->actingAs(panelStaff(['is_synapse_admin' => false]));
    $this->postJson('/ai-page-builder/lint/php', ['code' => 'echo 1;'])->assertForbidden();
});
