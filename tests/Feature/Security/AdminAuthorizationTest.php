<?php

declare(strict_types=1);

/*
 * Who may use the Synapse admin screens (GHSA-hj3q-8gc4-6mpw, F1 + F3).
 *
 * Synapse grants nothing by default: a host panel user reaches no Synapse
 * resource, page or relation manager until the host grants `synapse.admin`
 * (or passes ->authorize() to the plugin). Finer abilities narrow sensitive
 * areas, and the host's own model policies still apply on top.
 */

use Andre\AiPageBuilder\Filament\AiPageBuilderPlugin;
use Andre\AiPageBuilder\Filament\Pages\AppPortability;
use Andre\AiPageBuilder\Filament\Pages\PageBuilderSettings;
use Andre\AiPageBuilder\Filament\Resources\CredentialResource;
use Andre\AiPageBuilder\Filament\Resources\CredentialResource\Pages\EditCredential;
use Andre\AiPageBuilder\Filament\Resources\FunctionResource;
use Andre\AiPageBuilder\Filament\Resources\PageResource;
use Andre\AiPageBuilder\Filament\Resources\PageResource\Pages\EditPage;
use Andre\AiPageBuilder\Filament\Resources\PageResource\RelationManagers\RevisionsRelationManager;
use Andre\AiPageBuilder\Filament\Resources\PbApiTokenResource;
use Andre\AiPageBuilder\Filament\Resources\PbApiTokenResource\Pages\ListPbApiTokens;
use Andre\AiPageBuilder\Filament\Resources\PbUserResource\Pages\CreatePbUser;
use Andre\AiPageBuilder\Models\Credential;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbApiToken;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\Variable;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * The real token list page with ONLY its Blade view swapped: the table view
 * needs ext-intl, which the test host may lack. mount(), the access checks and
 * the 'create' header action are the unmodified package code.
 */
class TokenListWithoutTable extends ListPbApiTokens
{
    public function render(): View
    {
        $file = sys_get_temp_dir().'/pb_token_list_blank.blade.php';
        file_put_contents($file, '<div><x-filament-actions::modals /></div>');

        return view()->file($file);
    }
}

/** @return list<class-string> Every resource + page the plugin registers. */
function synapseScreens(): array
{
    $panel = Filament::getPanel('admin');

    return array_values(array_filter(
        array_merge($panel->getResources(), $panel->getPages()),
        fn (string $class): bool => str_starts_with($class, 'Andre\\AiPageBuilder\\'),
    ));
}

/** @return list<string> Short names of the screens the current user can open. */
function reachableScreens(): array
{
    $reachable = [];
    foreach (synapseScreens() as $class) {
        $isResource = method_exists($class, 'canViewAny');
        $open = $class::canAccess() || ($isResource && ($class::canViewAny() || $class::canCreate()));
        if ($open) {
            $reachable[] = class_basename($class);
        }
    }

    return $reachable;
}

beforeEach(function (): void {
    bootSynapsePanel();
});

it('registers all 15 resources and 4 pages behind the gate', function (): void {
    expect(synapseScreens())->toHaveCount(19);
});

it('denies every Synapse screen to a panel user the host has not granted', function (): void {
    $this->actingAs(panelStaff());

    expect(reachableScreens())->toBe([]);
});

it('opens every Synapse screen once the host defines synapse.admin', function (): void {
    $this->actingAs(synapseAdmin());

    expect(reachableScreens())->toHaveCount(19);
});

it('still denies a panel user the host ability does not cover', function (): void {
    grantSynapseToAdmins();
    $this->actingAs(panelStaff());

    expect(reachableScreens())->toBe([]);
});

it('lets the plugin ->authorize() callback decide instead of the gate', function (): void {
    $staff = panelStaff(['email' => 'trusted@example.test']);
    /** @var AiPageBuilderPlugin $plugin */
    $plugin = Filament::getPanel('admin')->getPlugin(AiPageBuilderPlugin::ID);
    $plugin->authorize(fn ($user): bool => $user->email === 'trusted@example.test');

    $this->actingAs($staff);
    expect(reachableScreens())->toHaveCount(19);

    $this->actingAs(panelStaff(['email' => 'other@example.test']));
    expect(reachableScreens())->toBe([]);

    $plugin->authorize(null);
});

it('narrows sensitive screens with a finer ability the host defines', function (): void {
    Gate::define('synapse.manage-secrets', fn (): bool => false);
    Gate::define('synapse.manage-users', fn (): bool => false);
    Gate::define('synapse.manage-code', fn (): bool => false);
    Gate::define('synapse.manage-settings', fn (): bool => false);
    $this->actingAs(synapseAdmin());

    expect(reachableScreens())->not->toContain(
        'CredentialResource', 'PbApiTokenResource',
        'PbUserResource', 'PbRoleResource', 'PbUserInviteResource',
        'FunctionResource', 'PageBuilderSettings', 'ThemeSettings',
    )->and(reachableScreens())->toContain('PageResource', 'FlowResource', 'AppPortability', 'CollectionsApiDocs');
});

it('never lets a finer ability widen access past synapse.admin', function (): void {
    Gate::define('synapse.manage-secrets', fn (): bool => true);
    $this->actingAs(panelStaff());

    expect(CredentialResource::canAccess())->toBeFalse()
        ->and(PbApiTokenResource::canCreate())->toBeFalse();
});

it('keeps the host model policies in force on top of the gate', function (): void {
    Gate::policy(Page::class, DenyAllPagesPolicy::class);
    $this->actingAs(synapseAdmin());

    expect(PageResource::canAccess())->toBeFalse()
        ->and(FunctionResource::canAccess())->toBeTrue();
});

it('does not render a relation manager for an ungranted user, even mounted directly', function (): void {
    $page = Page::query()->create(['title' => 'Home', 'slug' => 'home', 'status' => 'draft', 'html' => '<p>x</p>']);
    $this->actingAs(panelStaff());

    expect(RevisionsRelationManager::canViewForRecord($page, EditPage::class))->toBeFalse();

    Livewire::test(RevisionsRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditPage::class])
        ->assertForbidden();
});

it('refuses to mint an API token for an ungranted panel user', function (): void {
    $this->actingAs(panelStaff());

    Livewire::test(TokenListWithoutTable::class)->assertForbidden();

    expect(PbApiToken::query()->count())->toBe(0);
});

it('mints an API token for a granted admin and shows it once (Filament 4 and 5)', function (): void {
    $this->actingAs(synapseAdmin());

    Livewire::test(TokenListWithoutTable::class)
        ->callAction('create', data: ['name' => 'n8n', 'pb_user_id' => null, 'expires_in_days' => ''])
        ->assertHasNoActionErrors()
        ->assertNotified('Token created — shown once');

    expect(PbApiToken::query()->where('name', 'n8n')->exists())->toBeTrue();
});

it('refuses to create a built-app admin user for an ungranted panel user', function (): void {
    PbRole::query()->create(['name' => 'Admin', 'slug' => 'admin', 'is_admin' => true]);
    $this->actingAs(panelStaff());

    Livewire::test(CreatePbUser::class)->assertForbidden();

    expect(PbUser::query()->count())->toBe(0);
});

it('refuses the whole-app export to an ungranted panel user', function (): void {
    Variable::query()->create(['key' => 'api_key', 'type' => 'string', 'value' => 'state-secret-xyz']);
    $this->actingAs(panelStaff());

    Livewire::test(AppPortability::class)->assertForbidden();
});

it('gates export on manage-settings and import on manage-code', function (): void {
    $this->actingAs(synapseAdmin());

    Livewire::test(AppPortability::class)
        ->assertActionVisible('download')
        ->assertActionVisible('import')
        ->callAction('download')
        ->assertFileDownloaded();

    Gate::define('synapse.manage-settings', fn (): bool => false);
    Gate::define('synapse.manage-code', fn (): bool => false);

    Livewire::test(AppPortability::class)
        ->assertActionHidden('download')
        ->assertActionHidden('import');

    expect(PageBuilderSettings::canAccess())->toBeFalse();
});

it('never sends the stored credential secret to the browser', function (): void {
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk_live_TOPSECRET_123']);
    $this->actingAs(synapseAdmin());

    $component = Livewire::test(EditCredential::class, ['record' => 'stripe'])->assertOk();

    expect(json_encode($component->snapshot).$component->html())->not->toContain('sk_live_TOPSECRET_123');
});

it('keeps the secret when the credential is saved with the field left blank', function (): void {
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk_live_keep']);
    $this->actingAs(synapseAdmin());

    Livewire::test(EditCredential::class, ['record' => 'stripe'])
        ->fillForm(['name' => 'Stripe live'])
        ->call('save')
        ->assertHasNoFormErrors();

    $credential = Credential::query()->where('key', 'stripe')->firstOrFail();
    expect($credential->name)->toBe('Stripe live')
        ->and($credential->secret)->toBe('sk_live_keep');

    Livewire::test(EditCredential::class, ['record' => 'stripe'])
        ->fillForm(['secret' => 'sk_live_rotated'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($credential->fresh()->secret)->toBe('sk_live_rotated');
});

class DenyAllPagesPolicy
{
    public function viewAny(): bool
    {
        return false;
    }

    public function create(): bool
    {
        return false;
    }
}
