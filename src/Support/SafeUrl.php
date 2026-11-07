<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Support;

/**
 * URL checks for values that reach a visitor's browser from a flow (redirect
 * targets, links and images in flow HTML output).
 */
final class SafeUrl
{
    /**
     * True for an absolute http(s) URL or a relative one (path, query, hash).
     * Control characters and whitespace are stripped first, as browsers do when
     * they parse a URL, so `java\tscript:` can't slip past the scheme check.
     */
    public static function isNavigable(string $url): bool
    {
        $scheme = self::scheme($url);

        return $scheme === null || in_array($scheme, ['http', 'https'], true);
    }

    /**
     * True for a URL a link or embedded resource in flow HTML may carry:
     * {@see isNavigable()}, plus `mailto:` / `tel:` links and inline raster
     * images (`data:image/png|jpeg|gif|webp`). Script schemes, `data:` documents
     * and SVG data URIs are refused.
     */
    public static function isSafeAttributeUrl(string $url): bool
    {
        $scheme = self::scheme($url);
        if ($scheme === null || in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            return true;
        }

        $normalized = strtolower(self::normalize($url));

        return preg_match('#^data:image/(png|jpeg|jpg|gif|webp)[;,]#', $normalized) === 1;
    }

    /** The lower-cased scheme of a URL, or null when it is relative. */
    private static function scheme(string $url): ?string
    {
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', self::normalize($url), $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    private static function normalize(string $url): string
    {
        return (string) preg_replace('/[\x00-\x20\x7F]+/', '', $url);
    }
}
