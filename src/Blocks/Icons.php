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
        'navbar' => '<rect x="3" y="6" width="18" height="12" rx="2"/><rect x="3" y="6" width="18" height="12" rx="2" fill="currentColor" stroke="none" opacity="0.12"/><circle cx="6.8" cy="12" r="1.5" fill="currentColor" stroke="none" opacity="0.55"/><line x1="12" y1="12" x2="14" y2="12"/><line x1="16" y1="12" x2="18" y2="12"/>',
        'hero' => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="7" y="7" width="10" height="3" rx="1" fill="currentColor" stroke="none" opacity="0.28"/><line x1="8.5" y1="12" x2="15.5" y2="12"/><line x1="9.5" y1="14.2" x2="14.5" y2="14.2"/><rect x="9.5" y="16" width="5" height="2.4" rx="1.2" fill="currentColor" stroke="none" opacity="0.28"/>',
        'features' => '<rect x="4" y="4" width="7" height="7" rx="1.5" fill="currentColor" stroke="none" opacity="0.22"/><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5" fill="currentColor" stroke="none" opacity="0.22"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
        'logos' => '<rect x="3" y="10" width="4" height="4" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="3" y="10" width="4" height="4" rx="1"/><rect x="10" y="10" width="4" height="4" rx="1"/><rect x="17" y="10" width="4" height="4" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="17" y="10" width="4" height="4" rx="1"/>',
        'stats' => '<rect x="4" y="11" width="3.2" height="7" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="10.4" y="6" width="3.2" height="12" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="16.8" y="9" width="3.2" height="9" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><line x1="3" y1="20" x2="21" y2="20"/>',
        'gallery' => '<rect x="3" y="6" width="8" height="12" rx="1.5" fill="currentColor" stroke="none" opacity="0.22"/><rect x="3" y="6" width="8" height="12" rx="1.5"/><rect x="13" y="6" width="8" height="12" rx="1.5"/><circle cx="6" cy="9.5" r="1"/><path d="M13 15l3-3 5 5"/>',
        'pricing' => '<rect x="4" y="3" width="16" height="18" rx="2"/><rect x="7" y="6" width="8" height="4" rx="1" fill="currentColor" stroke="none" opacity="0.28"/><path d="M7 13.5l1.3 1.3 2.4-2.4"/><path d="M7 17.5l1.3 1.3 2.4-2.4"/>',
        'testimonial' => '<path d="M4 5h16v10H8l-4 4z" fill="currentColor" stroke="none" opacity="0.18"/><path d="M4 5h16v10H8l-4 4z"/><circle cx="9" cy="10" r="1.1" fill="currentColor" stroke="none" opacity="0.6"/><line x1="12" y1="10" x2="16" y2="10"/>',
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
        // Shape dividers
        'shape-wave' => '<path d="M3 12c3-4 6 4 9 0s6-4 9 0"/>',
        'shape-slant' => '<path d="M3 16L21 8"/><path d="M3 16h18"/>',
        'shape-tilt' => '<path d="M3 8l18 8"/><path d="M3 16h18"/>',
        'shape-curve' => '<path d="M3 14c4-8 14-8 18 0"/>',
        // Components
        'card' => '<rect x="4" y="5" width="16" height="14" rx="2"/><line x1="7" y1="9" x2="14" y2="9"/><line x1="7" y1="13" x2="17" y2="13"/><line x1="7" y1="16" x2="13" y2="16"/>',
        'banner' => '<rect x="3" y="8" width="18" height="8" rx="2"/><circle cx="7" cy="12" r="1.3"/><line x1="10" y1="12" x2="16" y2="12"/>',
        'modal' => '<rect x="3" y="4" width="18" height="16" rx="2" opacity="0.4"/><rect x="7" y="8" width="10" height="8" rx="1.5"/>',
        'drawer' => '<rect x="3" y="4" width="18" height="16" rx="2"/><rect x="14" y="4" width="7" height="16" rx="0"/>',
        'tabs' => '<path d="M3 9h5V5h5v4h8"/><rect x="3" y="9" width="18" height="10" rx="1.5"/>',
        'accordion' => '<rect x="3" y="4" width="18" height="5" rx="1.5"/><rect x="3" y="11" width="18" height="9" rx="1.5"/><path d="M17 6.5l1 1 1-1"/>',
        'tooltip' => '<rect x="4" y="5" width="16" height="9" rx="2"/><path d="M10 14l2 3 2-3"/>',
        'dropdown_menu' => '<rect x="6" y="4" width="12" height="5" rx="1.5"/><path d="M10 6.5l2 2 2-2"/><rect x="6" y="11" width="12" height="9" rx="1.5"/><line x1="9" y1="14" x2="15" y2="14"/><line x1="9" y1="17" x2="15" y2="17"/>',
        // Forms
        'text_input' => '<rect x="3" y="9" width="18" height="6" rx="1.5"/><line x1="7" y1="12" x2="7" y2="12.01"/>',
        'email_input' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 8l9 6 9-6"/>',
        'textarea' => '<rect x="3" y="5" width="18" height="14" rx="1.5"/><line x1="7" y1="9" x2="17" y2="9"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="7" y1="15" x2="13" y2="15"/>',
        'select' => '<rect x="3" y="8" width="18" height="8" rx="1.5"/><path d="M15 11l2 2 2-2"/>',
        'checkbox' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 12l3 3 5-6"/>',
        'radio_group' => '<circle cx="7" cy="7" r="3"/><circle cx="7" cy="7" r="1"/><line x1="13" y1="7" x2="20" y2="7"/><circle cx="7" cy="17" r="3"/><line x1="13" y1="17" x2="20" y2="17"/>',
        'submit_button' => '<rect x="4" y="9" width="16" height="6" rx="3"/><path d="M9 12h4"/><path d="M13 10.5l1.5 1.5-1.5 1.5"/>',
        'form' => '<rect x="4" y="3" width="16" height="18" rx="2"/><line x1="7.5" y1="7" x2="12" y2="7"/><rect x="7.5" y="8.4" width="9" height="2.6" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><line x1="7.5" y1="13.4" x2="12" y2="13.4"/><rect x="9" y="16.4" width="6" height="2.6" rx="1.3" fill="currentColor" stroke="none" opacity="0.28"/>',
        // Data
        'data_table' => '<rect x="3" y="4" width="18" height="16" rx="2"/><rect x="3" y="4" width="18" height="4.5" fill="currentColor" stroke="none" opacity="0.22"/><line x1="3" y1="12.5" x2="21" y2="12.5"/><line x1="3" y1="16.5" x2="21" y2="16.5"/><line x1="10" y1="8.5" x2="10" y2="20"/>',
        'chart' => '<rect x="4" y="12" width="3.5" height="6" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="10.25" y="8" width="3.5" height="10" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="16.5" y="5" width="3.5" height="13" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><line x1="3" y1="20" x2="21" y2="20"/>',
        'kpi' => '<rect x="4" y="5" width="16" height="14" rx="2"/><rect x="7" y="8.5" width="7" height="4.5" rx="1" fill="currentColor" stroke="none" opacity="0.28"/><line x1="7" y1="15.6" x2="13" y2="15.6"/>',
        'list' => '<circle cx="5" cy="7" r="1.2" fill="currentColor" stroke="none" opacity="0.5"/><line x1="9" y1="7" x2="20" y2="7"/><circle cx="5" cy="12" r="1.2" fill="currentColor" stroke="none" opacity="0.5"/><line x1="9" y1="12" x2="20" y2="12"/><circle cx="5" cy="17" r="1.2" fill="currentColor" stroke="none" opacity="0.5"/><line x1="9" y1="17" x2="20" y2="17"/>',
        // Interactive
        'repeater' => '<rect x="4" y="4" width="16" height="5" rx="1.5"/><rect x="4" y="11" width="16" height="5" rx="1.5"/><line x1="9" y1="19.5" x2="15" y2="19.5"/><line x1="12" y1="17" x2="12" y2="22"/>',
        'editable_grid' => '<rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="14" y1="5" x2="14" y2="19"/><path d="M6 14.5l1.5 1.5 3-3"/>',
        'stepper' => '<rect x="3" y="9" width="18" height="6" rx="3"/><line x1="6.5" y1="12" x2="8.5" y2="12"/><line x1="15.5" y1="12" x2="17.5" y2="12"/><line x1="16.5" y1="11" x2="16.5" y2="13"/>',
        'context_menu' => '<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>',
        'record_picker' => '<rect x="3" y="4" width="18" height="3" rx="1.5"/><rect x="4" y="9.5" width="7" height="4.5" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="4" y="9.5" width="7" height="4.5" rx="1"/><rect x="13" y="9.5" width="7" height="4.5" rx="1"/><rect x="4" y="15.5" width="7" height="4.5" rx="1"/><rect x="13" y="15.5" width="7" height="4.5" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="13" y="15.5" width="7" height="4.5" rx="1"/>',
    ];

    public static function for(string $key): string
    {
        $inner = self::GLYPHS[$key] ?? '<rect x="4" y="4" width="16" height="16" rx="2"/>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            .'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
            .'style="width:26px;height:26px;">'.$inner.'</svg>';
    }
}
