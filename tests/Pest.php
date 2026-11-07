<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Tests\Fixtures\PanelStaff;
use Andre\AiPageBuilder\Tests\Fixtures\SynapsePanelProvider;
use Andre\AiPageBuilder\Tests\TestCase;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;

uses(TestCase::class)->in(__DIR__);

/**
 * Boot Filament with a host panel that registers the Synapse plugin (as the
 * README instructs), plus the `panel_staff` users table. Idempotent per test.
 */
function bootSynapsePanel(): void
{
    if (Schema::hasTable('panel_staff')) {
        return;
    }

    foreach ([
        LivewireServiceProvider::class,
        BladeIconsServiceProvider::class,
        BladeHeroiconsServiceProvider::class,
        SupportServiceProvider::class,
        ActionsServiceProvider::class,
        FormsServiceProvider::class,
        InfolistsServiceProvider::class,
        NotificationsServiceProvider::class,
        SchemasServiceProvider::class,
        TablesServiceProvider::class,
        WidgetsServiceProvider::class,
        SynapsePanelProvider::class,
        FilamentServiceProvider::class,
    ] as $provider) {
        app()->register($provider);
    }

    Schema::create('panel_staff', function (Blueprint $t): void {
        $t->id();
        $t->string('name')->default('x');
        $t->string('email')->default('x@example.test');
        $t->string('password')->default('');
        $t->rememberToken();
        $t->boolean('can_access_panel')->default(true);
        $t->boolean('is_synapse_admin')->default(false);
        $t->timestamps();
    });

    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

/**
 * A panel user the host let into its Filament panel, with NO Synapse grant.
 *
 * @param  array<string,mixed>  $attributes
 */
function panelStaff(array $attributes = []): PanelStaff
{
    bootSynapsePanel();

    return PanelStaff::query()->create($attributes + [
        'name' => 'Support agent',
        'email' => 'agent+'.uniqid().'@example.test',
        'can_access_panel' => true,
        'is_synapse_admin' => false,
    ]);
}

/**
 * The host grants Synapse the documented way, then returns a granted user.
 *
 * @param  array<string,mixed>  $attributes
 */
function synapseAdmin(array $attributes = []): PanelStaff
{
    grantSynapseToAdmins();

    return panelStaff($attributes + ['name' => 'Builder admin', 'is_synapse_admin' => true]);
}

function grantSynapseToAdmins(): void
{
    Gate::define('synapse.admin', fn ($user): bool => (bool) $user->is_synapse_admin);
}
