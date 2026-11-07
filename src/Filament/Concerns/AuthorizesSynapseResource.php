<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Concerns;

use Andre\AiPageBuilder\Support\SynapseAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Gates a Synapse Filament RESOURCE behind the Synapse ability (deny by
 * default). Every Filament check — page access, viewAny, create, edit,
 * delete, bulk actions — goes through getAuthorizationResponse()/can(), so
 * the gate is applied first and the host's own model policies still apply
 * on top of it.
 *
 * A resource opts into a finer scope by overriding synapseAbility().
 */
trait AuthorizesSynapseResource
{
    public static function synapseAbility(): string
    {
        return SynapseAccess::ADMIN;
    }

    public static function canAccess(): bool
    {
        return SynapseAccess::check(static::synapseAbility()) && parent::canAccess();
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (! SynapseAccess::check(static::synapseAbility())) {
            return Response::deny();
        }

        return parent::getAuthorizationResponse($action, $record);
    }

    public static function can(string|UnitEnum $action, ?Model $record = null): bool
    {
        return SynapseAccess::check(static::synapseAbility()) && parent::can($action, $record);
    }
}
