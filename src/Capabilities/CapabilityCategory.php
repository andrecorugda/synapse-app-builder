<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities;

/**
 * The use-case groupings a capability (flow node or function helper) belongs to.
 * Drives the categorized, searchable node drawer and the helper dropdown — the
 * same way GrapesJS groups blocks by category. `order()` controls display order;
 * `label()` / `icon()` feed the UI and the MCP tool listing.
 */
enum CapabilityCategory: string
{
    case FlowControl = 'flow_control';
    case Data = 'data';
    case Ui = 'ui';
    case Communication = 'communication';
    case Integrations = 'integrations';
    case Ai = 'ai';
    case Auth = 'auth';
    case Util = 'util';

    /** Human label shown as the drawer/dropdown group heading. */
    public function label(): string
    {
        return match ($this) {
            self::FlowControl => 'Flow Control',
            self::Data => 'Data',
            self::Ui => 'UI & Feedback',
            self::Communication => 'Communication',
            self::Integrations => 'Integrations',
            self::Ai => 'AI',
            self::Auth => 'Auth & Access',
            self::Util => 'Utilities',
        };
    }

    /** Heroicon-style name; the frontend maps it to an SVG/glyph. */
    public function icon(): string
    {
        return match ($this) {
            self::FlowControl => 'arrows-right-left',
            self::Data => 'circle-stack',
            self::Ui => 'bell-alert',
            self::Communication => 'envelope',
            self::Integrations => 'puzzle-piece',
            self::Ai => 'sparkles',
            self::Auth => 'lock-closed',
            self::Util => 'wrench-screwdriver',
        };
    }

    /** Lower sorts first in the drawer. */
    public function order(): int
    {
        return match ($this) {
            self::FlowControl => 0,
            self::Data => 1,
            self::Ui => 2,
            self::Communication => 3,
            self::Integrations => 4,
            self::Ai => 5,
            self::Auth => 6,
            self::Util => 7,
        };
    }
}
