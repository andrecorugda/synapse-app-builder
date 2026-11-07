<?php

declare(strict_types=1);

/*
 * Admin-UI follow-ups for the 2.0.0 security release: the States visibility
 * toggle, flow/run permissions on a role, refused media uploads, and the
 * PbUser edit form (email / role are hidden from serialization).
 */

use Andre\AiPageBuilder\Filament\Resources\MediaResource\Pages\ListMedia;
use Andre\AiPageBuilder\Filament\Resources\PbRoleResource\Pages\EditPbRole;
use Andre\AiPageBuilder\Filament\Resources\PbRoleResource\RelationManagers\PermissionsRelationManager;
use Andre\AiPageBuilder\Filament\Resources\PbUserResource\Pages\EditPbUser;
use Andre\AiPageBuilder\Filament\Resources\VariableResource\Pages\CreateVariable;
use Andre\AiPageBuilder\Filament\Resources\VariableResource\Pages\EditVariable;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\MediaItem;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\Variable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * Real Filament pages/relation managers with ONLY their Blade view swapped: the
 * table views need ext-intl, which the test host may lack. Actions, access
 * checks and form handling are the unmodified package code.
 */
function blankFollowupView(): View
{
    $file = sys_get_temp_dir().'/pb_followup_blank.blade.php';
    file_put_contents($file, '<div><x-filament-actions::modals /></div>');

    return view()->file($file);
}

class ListMediaWithoutTable extends ListMedia
{
    public function render(): View
    {
        return blankFollowupView();
    }
}

class PermissionsWithoutTable extends PermissionsRelationManager
{
    public function render(): View
    {
        return blankFollowupView();
    }
}

it('shows a danger notification and stores nothing for an image type the library refuses (SVG)', function (): void {
    $this->actingAs(synapseAdmin());

    Livewire::test(ListMediaWithoutTable::class)
        ->callAction(TestAction::make('upload')->table(), data: ['files' => [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]])
        ->assertHasNoActionErrors()->assertNotified();

    expect(MediaItem::query()->count())->toBe(0);
});

it('creates a State that is visible to visitors through the form', function (): void {
    $this->actingAs(synapseAdmin());

    Livewire::test(CreateVariable::class)
        ->fillForm(['key' => 'site_name', 'type' => 'string', 'value' => 'Acme', 'is_public' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Variable::query()->where('key', 'site_name')->value('is_public'))->toBeTrue();
});

it('toggles is_public on an existing State and flushes through the model', function (): void {
    $this->actingAs(synapseAdmin());
    $state = Variable::query()->create(['key' => 'banner', 'type' => 'string', 'value' => 'hi']);
    expect((bool) $state->fresh()->is_public)->toBeFalse();

    Livewire::test(EditVariable::class, ['record' => $state->getRouteKey()])
        ->fillForm(['is_public' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($state->fresh()->is_public)->toBeTrue();
});

it('accepts a flow / run permission for a flow slug and for *', function (): void {
    $this->actingAs(synapseAdmin());
    $role = PbRole::query()->create(['name' => 'Staff', 'slug' => 'staff']);
    Flow::query()->create(['slug' => 'send-quote', 'name' => 'Send quote']);

    $component = Livewire::test(PermissionsWithoutTable::class, [
        'ownerRecord' => $role,
        'pageClass' => EditPbRole::class,
    ]);

    foreach (['send-quote', '*'] as $key) {
        $component
            ->callAction(TestAction::make('create')->table(), data: [
                'resource_type' => 'flow',
                'resource_key' => $key,
                'action' => 'run',
            ])
            ->assertHasNoFormErrors();
    }

    expect($role->permissions()->where('resource_type', 'flow')->where('action', 'run')->pluck('resource_key')->all())
        ->toEqualCanonicalizing(['send-quote', '*']);
});

it('fills the app-user edit form with the hidden email and role', function (): void {
    $this->actingAs(synapseAdmin());
    $role = PbRole::query()->create(['name' => 'Member', 'slug' => 'member']);
    $user = PbUser::query()->create([
        'name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret-pass-123', 'role_id' => $role->id,
    ]);

    Livewire::test(EditPbUser::class, ['record' => $user->getKey()])
        ->assertFormSet(['email' => 'ada@example.test', 'role_id' => $role->id]);
});
