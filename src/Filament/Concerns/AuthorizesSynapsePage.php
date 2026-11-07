<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Concerns;

use Andre\AiPageBuilder\Support\SynapseAccess;

/**
 * Gates a custom Synapse Filament PAGE behind the Synapse ability (deny by
 * default). Filament re-checks canAccess() on mount and on every Livewire
 * request, so a direct POST to the page component is refused as well.
 *
 * A page opts into a finer scope by overriding synapseAbility().
 */
trait AuthorizesSynapsePage
{
    public static function synapseAbility(): string
    {
        return SynapseAccess::ADMIN;
    }

    public static function canAccess(): bool
    {
        return SynapseAccess::check(static::synapseAbility()) && parent::canAccess();
    }
}
