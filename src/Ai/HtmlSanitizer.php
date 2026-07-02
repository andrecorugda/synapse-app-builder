<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Ai;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Sanitizes AI-generated page HTML before it is persisted to a Page.
 *
 * AI output is UNTRUSTED, so this removes anything that can execute script in a
 * visitor's browser while preserving the declarative markup the published-page
 * runtime relies on:
 *
 *   - strips <script>, <iframe>, <object>, <embed> and other active elements
 *   - strips inline event handlers (on*=) and javascript:/data:text/html URLs
 *   - strips EXECUTABLE Alpine directives: x-on:*, @*, x-init, x-effect, x-html
 *   - ALLOWS declarative Alpine: x-data, x-show, x-text, x-model, x-for,
 *     x-bind:* / :*, x-cloak, x-transition* and the data-pb-* convention
 *
 * Owner-authored blocks (BlockVocabulary) are trusted and are NOT routed here —
 * only the BuildPlanApplier passes AI page html through sanitize().
 */
final class HtmlSanitizer
{
    /**
     * Elements removed wholesale (tag + contents) — they can run script or
     * embed foreign documents.
     *
     * @var array<int,string>
     */
    private const FORBIDDEN_TAGS = [
        'script', 'iframe', 'object', 'embed', 'applet', 'noscript',
        'link', 'meta', 'base', 'frame', 'frameset',
        // SVG SMIL animation elements: <animate>/<set>/<animateMotion>/
        // <animateTransform> can animate an attribute (e.g. href) to a
        // javascript: value at runtime, bypassing the static URL-scheme check.
        // DOMDocument lowercases tag names, so match the lowercased forms.
        'animate', 'set', 'animatemotion', 'animatetransform',
    ];

    /**
     * Attributes whose value is a URL, so they must be scheme-checked.
     *
     * @var array<int,string>
     */
    private const URL_ATTRS = ['href', 'src', 'xlink:href', 'action', 'formaction', 'background', 'poster'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        // Parse as a fragment: wrap so DOMDocument doesn't inject <html>/<body>,
        // and force UTF-8 so multibyte content survives the round-trip.
        $wrapped = '<?xml encoding="UTF-8"?><div id="__pb_sanitize_root__">'.$html.'</div>';

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__pb_sanitize_root__');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->cleanNode($root);

        // Serialize the children of the wrapper, dropping the wrapper itself.
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            /** @var DOMNode $child */
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Depth-first scrub of an element and its subtree. Children are snapshotted
     * before recursion because removals mutate the live NodeList.
     */
    private function cleanNode(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::FORBIDDEN_TAGS, true)) {
                $element->removeChild($child);

                continue;
            }

            $this->cleanAttributes($child);
            $this->cleanNode($child);
        }
    }

    private function cleanAttributes(DOMElement $element): void
    {
        // Snapshot — removeAttribute mutates the live attribute map.
        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            if (! $attr instanceof DOMAttr) {
                continue;
            }

            $name = $attr->nodeName;
            $value = $attr->nodeValue ?? '';

            if (! $this->attributeAllowed($name, $value)) {
                $element->removeAttribute($name);
            }
        }
    }

    private function attributeAllowed(string $name, string $value): bool
    {
        $lower = strtolower($name);

        // Inline event handlers (onclick, onload, …) — always executable.
        if (str_starts_with($lower, 'on')) {
            return false;
        }

        // Alpine: @* and x-on:* register event listeners → executable.
        if (str_starts_with($name, '@') || str_starts_with($lower, 'x-on:')) {
            return false;
        }

        // Alpine directives that run arbitrary JS on init / reactively, or
        // inject unescaped markup.
        if (in_array($lower, ['x-init', 'x-effect', 'x-html'], true)) {
            return false;
        }

        // Declarative Alpine bindings are allowed: x-bind:* and the `:` shorthand
        // (e.g. :style, :aria-selected). These set attributes, not handlers.
        if (str_starts_with($lower, 'x-bind:') || str_starts_with($name, ':')) {
            return true;
        }

        // Remaining declarative Alpine directives.
        if ($lower === 'x-data'
            || $lower === 'x-show'
            || $lower === 'x-text'
            || $lower === 'x-model'
            || str_starts_with($lower, 'x-model.')
            || $lower === 'x-for'
            || $lower === 'x-cloak'
            || $lower === 'x-if'
            || $lower === 'x-ref'
            || str_starts_with($lower, 'x-transition')
        ) {
            return true;
        }

        // Any other unknown x-* directive is treated as unsafe by default.
        if (str_starts_with($lower, 'x-')) {
            return false;
        }

        // data-pb-* convention + standard data-* are safe.
        if (str_starts_with($lower, 'data-')) {
            return true;
        }

        // URL-bearing attributes must carry a safe scheme.
        if (in_array($lower, self::URL_ATTRS, true)) {
            return $this->urlAllowed($value);
        }

        // style is allowed (no script vectors in modern browsers' inline CSS);
        // everything else (class, id, role, aria-*, type, name, alt, …) passes.
        return true;
    }

    /**
     * Reject javascript:/vbscript: and data: documents; allow http(s), mailto,
     * tel, fragment, relative, and inline data:image/* used by block templates.
     */
    private function urlAllowed(string $value): bool
    {
        $trimmed = strtolower(trim($value));

        // Strip leading control/whitespace chars browsers ignore when parsing.
        $trimmed = preg_replace('/[\x00-\x20]+/', '', $trimmed) ?? $trimmed;

        if ($trimmed === '') {
            return true;
        }

        if (str_starts_with($trimmed, 'javascript:') || str_starts_with($trimmed, 'vbscript:')) {
            return false;
        }

        // Permit raster image data URIs (used by gallery/team blocks); block
        // data:text/html AND data:image/svg+xml — SVG is a scriptable document
        // type (can carry <script>/on* handlers), so it is NOT a safe inline URL.
        if (str_starts_with($trimmed, 'data:')) {
            if (str_starts_with($trimmed, 'data:image/svg+xml')) {
                return false;
            }

            return str_starts_with($trimmed, 'data:image/');
        }

        return true;
    }
}
