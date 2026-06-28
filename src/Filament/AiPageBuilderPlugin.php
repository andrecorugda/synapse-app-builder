<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament;

use Andre\AiPageBuilder\Filament\Pages\BuildWithAi;
use Andre\AiPageBuilder\Filament\Pages\PageBuilderSettings;
use Andre\AiPageBuilder\Filament\Resources\FlowResource;
use Andre\AiPageBuilder\Filament\Resources\FunctionResource;
use Andre\AiPageBuilder\Filament\Resources\MediaResource;
use Andre\AiPageBuilder\Filament\Resources\PageResource;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource;
use Andre\AiPageBuilder\Filament\Resources\VariableResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament plugin exposing the AI Page Builder admin UI.
 *
 * Host apps opt in on their panel:
 *
 *     use Andre\AiPageBuilder\Filament\AiPageBuilderPlugin;
 *
 *     $panel->plugin(AiPageBuilderPlugin::make());
 */
class AiPageBuilderPlugin implements Plugin
{
    public function getId(): string
    {
        return 'ai-page-builder';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            PageResource::class,
            MediaResource::class,
            FlowResource::class,
            FunctionResource::class,
            VariableResource::class,
            PbModelResource::class,
        ]);

        $panel->pages([
            BuildWithAi::class,
            PageBuilderSettings::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        // Registration is enough.
    }
}
