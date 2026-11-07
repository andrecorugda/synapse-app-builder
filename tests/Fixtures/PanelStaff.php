<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host-app user of the Filament panel. `can_access_panel` models whether the
 * HOST lets them into its panel; `is_synapse_admin` is what the tests' Gate
 * definition grants Synapse on (the host decides; Synapse grants nothing).
 */
class PanelStaff extends Authenticatable implements FilamentUser
{
    protected $table = 'panel_staff';

    protected $guarded = [];

    protected $casts = [
        'can_access_panel' => 'boolean',
        'is_synapse_admin' => 'boolean',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->getAttribute('can_access_panel');
    }
}
