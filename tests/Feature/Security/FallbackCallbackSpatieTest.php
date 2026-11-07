<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Filament\AiPageBuilderPlugin;
use Andre\AiPageBuilder\Support\SynapseAccess;
use Andre\AiPageBuilder\Tests\Fixtures\FakePermissionRegistrar;
use Filament\Panel;
use Filament\PanelRegistry;

afterEach(function (): void {
    FakePermissionRegistrar::$names = [];
});

it('denies a finer ability registered as a spatie permission nobody holds, on a plugin ->authorize() host', function (): void {
    bootSynapsePanel();
    if (! class_exists('Spatie\\Permission\\PermissionRegistrar')) {
        class_alias(FakePermissionRegistrar::class, 'Spatie\\Permission\\PermissionRegistrar');
    }
    // Only owners hold synapse.manage-secrets in spatie; this builder does not.
    FakePermissionRegistrar::$names = ['synapse.manage-secrets'];
    app()->instance('Spatie\\Permission\\PermissionRegistrar', new FakePermissionRegistrar);

    $panel = Panel::make()->id('builder')->path('builder')
        ->plugin(AiPageBuilderPlugin::make()->authorize(fn ($user): bool => (bool) $user->is_synapse_admin));
    app(PanelRegistry::class)->register($panel);
    config()->set('ai-page-builder.filament.panel', 'builder');

    $user = panelStaff(['is_synapse_admin' => true]);

    expect(SynapseAccess::allows($user, SynapseAccess::ADMIN, $panel))->toBeTrue()
        // docs: "spatie has a permission with the ability's name -> denies"
        ->and(SynapseAccess::allows($user, SynapseAccess::MANAGE_SECRETS, $panel))->toBeFalse();
});
