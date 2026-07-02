<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament;

use Andre\AiPageBuilder\Filament\Pages\AppPortability;
use Andre\AiPageBuilder\Filament\Pages\CollectionsApiDocs;
use Andre\AiPageBuilder\Filament\Pages\PageBuilderSettings;
use Andre\AiPageBuilder\Filament\Pages\ThemeSettings;
use Andre\AiPageBuilder\Filament\Resources\CredentialResource;
use Andre\AiPageBuilder\Filament\Resources\FlowResource;
use Andre\AiPageBuilder\Filament\Resources\FunctionResource;
use Andre\AiPageBuilder\Filament\Resources\MediaResource;
use Andre\AiPageBuilder\Filament\Resources\PageResource;
use Andre\AiPageBuilder\Filament\Resources\PartialResource;
use Andre\AiPageBuilder\Filament\Resources\PbApiTokenResource;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource;
use Andre\AiPageBuilder\Filament\Resources\PbRoleResource;
use Andre\AiPageBuilder\Filament\Resources\PbUserInviteResource;
use Andre\AiPageBuilder\Filament\Resources\PbUserResource;
use Andre\AiPageBuilder\Filament\Resources\RecordRevisionResource;
use Andre\AiPageBuilder\Filament\Resources\ScheduleResource;
use Andre\AiPageBuilder\Filament\Resources\VariableResource;
use Andre\AiPageBuilder\Filament\Resources\WatcherResource;
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
            ScheduleResource::class,
            WatcherResource::class,
            PbModelResource::class,
            RecordRevisionResource::class,
            PbUserResource::class,
            PbRoleResource::class,
            PbUserInviteResource::class,
            PbApiTokenResource::class,
            PartialResource::class,
            CredentialResource::class,
        ]);

        $panel->pages([
            PageBuilderSettings::class,
            ThemeSettings::class,
            AppPortability::class,
            CollectionsApiDocs::class,
        ]);

        // Render the Synapse menu groups in a sensible top-down order. Distinct
        // labels only (so merging groups via config still works).
        $groups = array_values(array_unique(array_values(
            (array) config('ai-page-builder.filament.navigation_groups', []),
        )));
        if ($groups !== []) {
            $panel->navigationGroups($groups);
        }

        // Give every Synapse page more room — long forms (Pages, Records) and the
        // flow canvas all benefit from the extra width. Uniform across the panel via
        // one setting; a host can dial it back (or null it) through config. Passed as
        // a STRING (maxContentWidth accepts Width|string|null) so it works whether the
        // installed Filament exposes the enum as MaxWidth (v3) or Width (v4+).
        $maxWidth = config('ai-page-builder.filament.max_content_width', 'full');
        if ($maxWidth) {
            $panel->maxContentWidth((string) $maxWidth);
        }
    }

    public function boot(Panel $panel): void
    {
        // Registration is enough.
    }
}
