<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Facades;

use Andre\AiPageBuilder\Services\PageBuilderManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string render(\Andre\AiPageBuilder\Models\Page $page)
 * @method static void forget(string $slug)
 *
 * @see PageBuilderManager
 */
class PageBuilder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PageBuilderManager::class;
    }
}
