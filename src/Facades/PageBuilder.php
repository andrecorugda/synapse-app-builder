<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Facades;

use Andre\AiPageBuilder\Services\PageBuilderManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string render(\Andre\AiPageBuilder\Models\Page $page)
 * @method static void forget(string $slug)
 * @method static void registerNode(\Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler $handler)
 * @method static void registerHelper(\Andre\AiPageBuilder\Capabilities\CapabilityDefinition $definition, callable $fn)
 * @method static void registerComponent(\Andre\AiPageBuilder\Blocks\SectionBlock $block)
 * @method static array<int,array<string,mixed>> components()
 * @method static array<int,array<string,mixed>> capabilities()
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
