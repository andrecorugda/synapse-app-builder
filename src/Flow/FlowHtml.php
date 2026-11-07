<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Ai\HtmlSanitizer;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Renders the HTML a flow sends to the page (`setHtml` / `modal` html, a modal
 * Partial) so no token value can run script, whatever attribute it lands in.
 *
 * HTML-escaping a value is enough between tags, but not inside an attribute
 * whose value the browser treats as code or a URL: in `onclick="f('{{ x }}')"`
 * the escaped `&apos;` is decoded back to `'` before the handler runs, and in
 * `href="{{ x }}"` a `javascript:` value needs no special characters at all.
 *
 * So the template is rendered in three steps:
 *
 *  1. Every token is swapped for an inert placeholder and the template is
 *     parsed. A token that sits in an attribute holding JavaScript (on*, @*,
 *     any Alpine `x-*` / `:*` directive) or `srcdoc` drops that attribute; a
 *     token in a tag or attribute NAME drops the element / attribute.
 *  2. Placeholders are replaced by the values: HTML-escaped, except a
 *     `{{{ raw }}}` token between tags, which is inserted as HTML after being
 *     sanitised on its own with every Alpine directive stripped.
 *  3. The result is run through {@see HtmlSanitizer} in strict-URL mode: no
 *     script elements, no event handlers, and `href` / `src` / `action` / …
 *     must be http(s), relative, `mailto:`, `tel:` or a raster `data:image`.
 */
final class FlowHtml
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function render(string $template, FlowContext $context): string
    {
        if (trim($template) === '') {
            return '';
        }

        $nonce = 'pbtok'.bin2hex(random_bytes(6));

        /** @var array<string,array{path:string,raw:bool}> $tokens */
        $tokens = [];
        $marked = (string) preg_replace_callback(FlowContext::TOKEN, function (array $m) use (&$tokens, $nonce): string {
            $id = $nonce.'n'.count($tokens).'e';
            $tokens[$id] = ['path' => $m[2], 'raw' => $m[1] === '{'];

            return $id;
        }, $template);

        if ($tokens === []) {
            return $this->sanitizer->sanitize($template, strictUrls: true);
        }

        [$html, $inAttribute] = $this->neutralize($marked, $nonce);

        $replacements = [];
        foreach ($tokens as $id => $token) {
            $value = $context->stringValue($token['path']);
            $replacements[$id] = $token['raw'] && ! isset($inAttribute[$id])
                ? $this->sanitizer->sanitize($value, strictUrls: true, allowAlpine: false)
                : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        return $this->sanitizer->sanitize(strtr($html, $replacements), strictUrls: true);
    }

    /**
     * Parse the placeholder template, drop the attributes / elements where a
     * token would be code, and serialise it again.
     *
     * @return array{0:string,1:array<string,true>} the html, and the placeholders found in attribute values
     */
    private function neutralize(string $marked, string $nonce): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"?><div id="__pb_flow_root__">'.$marked.'</div>';

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__pb_flow_root__');
        if (! $root instanceof DOMElement) {
            return ['', []];
        }

        $inAttribute = [];
        $this->walk($root, $nonce, $inAttribute);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            /** @var DOMNode $child */
            $out .= $dom->saveHTML($child);
        }

        return [$out, $inAttribute];
    }

    /**
     * @param  array<string,true>  $inAttribute
     */
    private function walk(DOMElement $element, string $nonce, array &$inAttribute): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            // A token as (part of) a tag name could become any element: unwrap
            // it, keeping its content (which is walked like any other).
            if (stripos($child->nodeName, $nonce) !== false) {
                foreach (iterator_to_array($child->childNodes) as $grandchild) {
                    $element->insertBefore($grandchild, $child);
                }
                $element->removeChild($child);
                $this->walk($element, $nonce, $inAttribute);

                return;
            }

            foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                if (! $attr instanceof DOMAttr) {
                    continue;
                }

                $name = $attr->nodeName;
                $value = (string) $attr->nodeValue;

                if (stripos($name, $nonce) !== false) {
                    $child->removeAttribute($name);

                    continue;
                }

                if (! str_contains($value, $nonce)) {
                    continue;
                }

                if ($this->isCodeAttribute($name)) {
                    $child->removeAttribute($name);

                    continue;
                }

                preg_match_all('/'.preg_quote($nonce, '/').'n\d+e/', $value, $m);
                foreach ($m[0] as $id) {
                    $inAttribute[$id] = true;
                }
            }

            $this->walk($child, $nonce, $inAttribute);
        }
    }

    /** Attributes whose value the browser (or Alpine) runs as code, or parses as a document. */
    private function isCodeAttribute(string $name): bool
    {
        $lower = strtolower($name);

        return str_starts_with($lower, 'on')
            || str_starts_with($lower, '@')
            || str_starts_with($lower, 'x-')
            || str_starts_with($lower, ':')
            || $lower === 'srcdoc';
    }
}
