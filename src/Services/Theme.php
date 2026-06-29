<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

/**
 * Global theme tokens — one place to define a site's brand (colours, fonts,
 * shape). The tokens are emitted as `:root` CSS custom properties into every
 * rendered page AND the builder canvas, and named in the AI prompt, so pages
 * (hand-built or AI-generated) can reference `var(--pb-primary)` etc. and the
 * whole site re-skins from a single edit. Stored in the `theme` setting.
 */
class Theme
{
    /** @var array<string,string> */
    public const DEFAULTS = [
        'primary' => '#6366f1',
        'accent' => '#22d3ee',
        'ink' => '#0f172a',
        'muted' => '#64748b',
        'bg' => '#ffffff',
        'surface' => '#f8fafc',
        'border' => '#e2e8f0',
        'font' => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'heading_font' => '',
        'radius' => '0.75rem',
        'max_width' => '1140px',
    ];

    /** token => CSS custom-property name. The stable contract pages bind to. */
    public const VARS = [
        'primary' => '--pb-primary',
        'accent' => '--pb-accent',
        'ink' => '--pb-ink',
        'muted' => '--pb-muted',
        'bg' => '--pb-bg',
        'surface' => '--pb-surface',
        'border' => '--pb-border',
        'font' => '--pb-font',
        'heading_font' => '--pb-heading-font',
        'radius' => '--pb-radius',
        'max_width' => '--pb-max',
    ];

    public function __construct(private readonly Settings $settings) {}

    /**
     * The effective tokens (defaults merged with the saved overrides).
     *
     * @return array<string,string>
     */
    public function tokens(): array
    {
        $saved = $this->settings->get('theme', []);

        return array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);
    }

    /** The `:root { … }` block to inject wherever pages are rendered/previewed. */
    public function css(): string
    {
        $tokens = $this->tokens();
        // heading_font falls back to the body font when blank.
        if (($tokens['heading_font'] ?? '') === '') {
            $tokens['heading_font'] = $tokens['font'];
        }

        $decls = [];
        foreach (self::VARS as $key => $var) {
            $decls[] = $var.':'.$this->sanitize((string) ($tokens[$key] ?? ''));
        }

        return ':root{'.implode(';', $decls).'}';
    }

    /** Strip characters that could break out of the CSS declaration/block. */
    private function sanitize(string $value): string
    {
        return trim((string) preg_replace('/[<>{};]/', '', $value));
    }
}
