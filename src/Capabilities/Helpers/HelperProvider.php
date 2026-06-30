<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities\Helpers;

use Andre\AiPageBuilder\Capabilities\HelperRegistry;

/**
 * A group of related function helpers (a category's worth) that registers itself
 * into the {@see HelperRegistry}. Core providers are listed in the service
 * provider; third-party packages implement this and register their own.
 */
interface HelperProvider
{
    public function register(HelperRegistry $registry): void;
}
