<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament;

use Andre\AiPageBuilder\Filament\Pages\AppPortability;
use Andre\AiPageBuilder\Filament\Pages\CollectionsApiDocs;
use Andre\AiPageBuilder\Filament\Pages\PageBuilderSettings;
use Andre\AiPageBuilder\Filament\Pages\ThemeSettings;
use Andre\AiPageBuilder\Filament\Resources\FlowResource;
use Andre\AiPageBuilder\Filament\Resources\FlowRunResource;
use Andre\AiPageBuilder\Filament\Resources\FunctionResource;
use Andre\AiPageBuilder\Filament\Resources\MediaResource;
use Andre\AiPageBuilder\Filament\Resources\PageResource;
use Andre\AiPageBuilder\Filament\Resources\PartialResource;
use Andre\AiPageBuilder\Filament\Resources\PbApiTokenResource;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource;
use Andre\AiPageBuilder\Filament\Resources\PbRoleResource;
use Andre\AiPageBuilder\Filament\Resources\PbUserResource;
use Andre\AiPageBuilder\Filament\Resources\ScheduleResource;
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
            FlowRunResource::class,
            FunctionResource::class,
            VariableResource::class,
            ScheduleResource::class,
            PbModelResource::class,
            PbUserResource::class,
            PbRoleResource::class,
            PbApiTokenResource::class,
            PartialResource::class,
        ]);

        $panel->pages([
            PageBuilderSettings::class,
            ThemeSettings::class,
            AppPortability::class,
            CollectionsApiDocs::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        // Registration is enough.
    }
}
