<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament;

use Andre\AiPageBuilder\Filament\Resources\MediaResource;
use Andre\AiPageBuilder\Filament\Resources\PageResource;
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
        ]);
    }

    public function boot(Panel $panel): void
    {
        // Registration is enough.
    }
}
