<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Blocks;

/**
 * Inline SVG icons shown as each block's "media" in the GrapesJS block manager.
 * Monochrome (currentColor) outline glyphs, sized for the palette tiles.
 */
final class Icons
{
    /** @var array<string,string> key => inner SVG markup */
    private const GLYPHS = [
        // Sections
        'navbar' => '<rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/>',
        'hero' => '<rect x="3" y="5" width="18" height="14" rx="2"/><line x1="7" y1="11" x2="17" y2="11"/><line x1="9" y1="14" x2="15" y2="14"/>',
        'features' => '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
        'logos' => '<rect x="3" y="10" width="4" height="4" rx="1"/><rect x="10" y="10" width="4" height="4" rx="1"/><rect x="17" y="10" width="4" height="4" rx="1"/>',
        'stats' => '<line x1="5" y1="20" x2="5" y2="13"/><line x1="12" y1="20" x2="12" y2="7"/><line x1="19" y1="20" x2="19" y2="11"/>',
        'gallery' => '<rect x="3" y="6" width="8" height="12" rx="1"/><rect x="13" y="6" width="8" height="12" rx="1"/><circle cx="6" cy="9.5" r="1"/>',
        'pricing' => '<path d="M4 4h7l9 9-7 7-9-9z"/><circle cx="8" cy="8" r="1"/>',
        'testimonial' => '<path d="M4 5h16v10H8l-4 4z"/>',
        'faq' => '<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M16 6l1.5 1.5L19 6"/>',
        'team' => '<circle cx="9" cy="9" r="3"/><path d="M4 19c0-3 2.2-5 5-5s5 2 5 5"/><circle cx="17" cy="9" r="2.3"/>',
        'cta' => '<path d="M4 10v4l10 5V5z"/><path d="M14 8a4 4 0 0 1 0 8"/>',
        'contact' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'footer' => '<rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="15" x2="21" y2="15"/>',
        // Basics
        'text' => '<line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="14" y2="17"/>',
        'heading' => '<line x1="6" y1="5" x2="6" y2="19"/><line x1="18" y1="5" x2="18" y2="19"/><line x1="6" y1="12" x2="18" y2="12"/>',
        'image' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="M21 16l-5-5-4 4-2-2-4 4"/>',
        'button' => '<rect x="4" y="9" width="16" height="6" rx="3"/>',
        'columns-2' => '<rect x="4" y="5" width="7" height="14" rx="1"/><rect x="13" y="5" width="7" height="14" rx="1"/>',
        'columns-3' => '<rect x="3" y="5" width="5" height="14" rx="1"/><rect x="9.5" y="5" width="5" height="14" rx="1"/><rect x="16" y="5" width="5" height="14" rx="1"/>',
        'spacer' => '<line x1="12" y1="4" x2="12" y2="20"/><path d="M8 8l4-4 4 4"/><path d="M8 16l4 4 4-4"/>',
        'divider' => '<line x1="4" y1="12" x2="20" y2="12"/>',
    ];

    public static function for(string $key): string
    {
        $inner = self::GLYPHS[$key] ?? '<rect x="4" y="4" width="16" height="16" rx="2"/>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            .'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
            .'style="width:26px;height:26px;">'.$inner.'</svg>';
    }
}
