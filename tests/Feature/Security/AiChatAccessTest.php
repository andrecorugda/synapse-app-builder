<?php

declare(strict_types=1);

/*
 * The AI chat respects the finer abilities and is shown only to Synapse
 * admins (adversarial review A4, A5).
 *
 *  - A plan with a `settings` section needs synapse.manage-settings; settings
 *    that open sign-up or pick the default role also need manage-users (the
 *    Settings page applies the same rule to its sign-up fields).
 *  - The "Build with AI" dock renders only for a user who passes synapse.admin
 *    on a panel that runs Synapse.
 */

use Andre\AiPageBuilder\Filament\Pages\PageBuilderSettings;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Services\Settings;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    bootSynapsePanel();
});

/** The Settings page with its page pickers cleared (no pages exist in these tests). */
function settingsPage(): Testable
{
    return Livewire::test(PageBuilderSettings::class)
        ->set('data.home_page', null)
        ->set('data.not_found_page', null)
        ->set('data.maintenance_page', null);
}

function maintenanceOn(): bool
{
    return filter_var(app(Settings::class)->get('maintenance_mode', false), FILTER_VALIDATE_BOOLEAN);
}

it('refuses a plan with settings to a builder without manage-settings', function (): void {
    Gate::define('synapse.manage-settings', fn (): bool => false);
    $this->actingAs(synapseAdmin());

    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => [
        'pages' => [['slug' => 'pwned', 'title' => 'Pwned', 'html' => '<p>x</p>']],
        'settings' => ['maintenance_mode' => true, 'home_page' => 'pwned'],
    ]])->assertForbidden();

    expect(maintenanceOn())->toBeFalse()
        ->and(app(Settings::class)->get('home_page', 'home'))->toBe('home');
});

it('still applies a plan without settings for that builder', function (): void {
    Gate::define('synapse.manage-settings', fn (): bool => false);
    $this->actingAs(synapseAdmin());

    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => [
        'pages' => [['slug' => 'about', 'title' => 'About', 'html' => '<p>x</p>']],
    ]])->assertOk();
});

it('applies plan settings for a builder with manage-settings', function (): void {
    $this->actingAs(synapseAdmin());

    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => ['settings' => ['maintenance_mode' => true]]])
        ->assertOk();

    expect(maintenanceOn())->toBeTrue();
});

it('refuses sign-up and default-role settings in a plan without manage-users', function (array $settings): void {
    Gate::define('synapse.manage-users', fn (): bool => false);
    $this->actingAs(synapseAdmin());

    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => ['settings' => $settings]])
        ->assertForbidden();
})->with([
    'flat registration' => [['registration_enabled' => true]],
    'dotted default role' => [['auth.default_role' => 'admin']],
    'nested auth' => [['auth' => ['registration_mode' => 'open']]],
    'allowed domains' => [['allowed_email_domains' => ['evil.test']]],
]);

it('keeps the sign-up settings on the Settings page unchanged without manage-users', function (): void {
    Gate::define('synapse.manage-users', fn (): bool => false);
    PbRole::query()->create(['name' => 'Member', 'slug' => 'member']);
    PbRole::query()->create(['name' => 'Admin', 'slug' => 'admin']);
    app(Settings::class)->set('auth.registration_enabled', true);
    app(Settings::class)->set('auth.registration_mode', 'approval');
    app(Settings::class)->set('auth.default_role', 'member');
    $this->actingAs(synapseAdmin());

    settingsPage()
        ->assertFormFieldIsDisabled('registration_enabled')
        ->assertFormFieldIsDisabled('default_role')
        ->set('data.registration_mode', 'open')
        ->set('data.default_role', 'admin')
        ->set('data.maintenance_mode', true)
        ->call('save')
        ->assertHasNoErrors();

    // Neither changed by the user nor clobbered by the save.
    expect(filter_var(app(Settings::class)->get('auth.registration_enabled'), FILTER_VALIDATE_BOOLEAN))->toBeTrue()
        ->and(app(Settings::class)->get('auth.registration_mode'))->toBe('approval')
        ->and(app(Settings::class)->get('auth.default_role'))->toBe('member')
        // The rest of the page still saves.
        ->and(maintenanceOn())->toBeTrue();
});

it('saves the sign-up settings for a builder with manage-users', function (): void {
    $this->actingAs(synapseAdmin());

    settingsPage()
        ->set('data.registration_enabled', true)
        ->set('data.registration_mode', 'open')
        ->call('save')
        ->assertHasNoErrors();

    expect(filter_var(app(Settings::class)->get('auth.registration_enabled'), FILTER_VALIDATE_BOOLEAN))->toBeTrue()
        ->and(app(Settings::class)->get('auth.registration_mode'))->toBe('open');
});

it('does not render the AI chat dock for a panel user without synapse.admin', function (): void {
    $this->actingAs(panelStaff());

    expect((string) FilamentView::renderHook('panels::body.end'))->not->toContain('pb-aichat');
});

it('renders the AI chat dock for a Synapse admin', function (): void {
    $this->actingAs(synapseAdmin());

    expect((string) FilamentView::renderHook('panels::body.end'))->toContain('pb-aichat');
});

it('does not render the AI chat dock on a panel that does not run Synapse', function (): void {
    app(PanelRegistry::class)->register(Panel::make()->id('crm')->path('crm'));
    Filament::setCurrentPanel(Filament::getPanel('crm'));
    $this->actingAs(synapseAdmin());

    expect((string) FilamentView::renderHook('panels::body.end'))->not->toContain('pb-aichat');
});
