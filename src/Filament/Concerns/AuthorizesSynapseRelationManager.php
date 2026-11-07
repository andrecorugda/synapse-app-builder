<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Concerns;

use Andre\AiPageBuilder\Support\SynapseAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Gates a Synapse RELATION MANAGER: it is only shown when its owner resource
 * may be accessed, and every Livewire request to the component itself is
 * re-checked (so it cannot be reached around its owner page).
 */
trait AuthorizesSynapseRelationManager
{
    public static function synapseAbility(): string
    {
        return SynapseAccess::ADMIN;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! SynapseAccess::check(static::synapseAbility())) {
            return false;
        }

        if (method_exists($pageClass, 'getResource') && ! $pageClass::getResource()::canAccess()) {
            return false;
        }

        return parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /** Livewire trait hook: runs on mount and on every subsequent request. */
    public function bootAuthorizesSynapseRelationManager(): void
    {
        abort_unless(SynapseAccess::check(static::synapseAbility()), 403);
    }
}
