---
name: component-icons
description: >
  Generate premium COLORED two-tone (duotone) SVG icons for Synapse block
  components from the Phosphor icon set, colorized per category, that reflect what
  each component renders. Use when adding a block or refreshing the icons in
  src/Blocks/Icons.php so the block palette looks like an expensive library.
---

# Synapse component icons — colored Phosphor duotone

Goal: a colorful, premium block palette where each icon (a) is a real, polished glyph
and (b) depicts what the component renders (table→table, chart→bars, hero→H₁).

## Source & license

**Phosphor Icons — duotone weight (MIT, ~9,000 icons, bundle freely).** Fetch raw SVGs:
`https://unpkg.com/@phosphor-icons/core@2.1.1/assets/duotone/<name>-duotone.svg`.
Each is `<svg viewBox="0 0 256 256" fill="currentColor"><path opacity="0.2" …/><path …/></svg>`
— a solid main path + a tinted mass. Colorize by setting the fill to a category color;
the opacity-0.2 path becomes a light shade automatically → colored duotone.

## Where icons live (target)

`src/Blocks/Icons.php` → `const GLYPHS = [ '<key>' => '<full colored svg>' ]`, returned
verbatim by `Icons::for($key)` as the GrapesJS block `media`. Each value is a FULL
self-contained `<svg>` (viewBox 0 0 256 256).

## CRITICAL gotcha — color via inline STYLE, not the fill attribute

GrapesJS's block-manager CSS sets `svg { fill: … }`, and a CSS rule **beats the SVG
`fill` presentation attribute** — so `<svg fill="#6366F1">` renders GRAY. Put the color
in an **inline style** (inline style beats the stylesheet):
`<svg style="width:26px;height:26px;fill:#6366F1" viewBox="0 0 256 256">…</svg>`.
Verify with `getComputedStyle(svg).fill` — it must equal the intended color, not gray.
(This is the one that will silently bite you; always check computed fill in-browser.)

## Category palette (premium, cohesive)

| category | color |
|---|---|
| Sections | indigo `#6366F1` |
| Basics | slate `#64748B` |
| Shape dividers | cyan `#06B6D4` |
| Components | violet `#8B5CF6` |
| Forms | amber `#F59E0B` |
| Data | emerald `#10B981` |
| Interactive | rose `#F43F5E` |

## Metaphor: icon = the rendered output

Map each block key → a Phosphor name that depicts its result. Current mapping lives in
`generate.sh` (this dir). Examples: data_table→`table`, chart→`chart-bar`, kpi→`gauge`,
hero→`text-h-one`, navbar→`browser`, form→`note-pencil`, record_picker→`list-magnifying-glass`,
pricing→`tag`, testimonial→`quotes`, team→`users-three`, select→`list-dashes`.

## Generate / regenerate (the whole set)

1. Edit `generate.sh` (this dir): the `MAP` is `synapse_key|phosphor-name|hex_color`,
   one line per block. **Cover EVERY block key** — diff against
   `BlockVocabulary::all()` keys so nothing falls back to the default square:
   `php artisan tinker --execute="echo collect(BlockVocabulary::all())->map(fn(\$b)=>\$b->key)->implode(' ');"`
2. Run `generate.sh` — it fetches each duotone SVG, sets the fill **in the inline style**,
   collapses to one line, and emits `glyphs.php`. It LOGS any Phosphor name that 404s to
   `glyphs.miss` — fix those names (don't let them silently fall back).
3. Splice `glyphs.php` into `Icons::GLYPHS`; keep `Icons::for()` returning the full svg
   (with a slate fallback). `php -l` it.
4. **Verify in-browser**: open the editor, screenshot the block palette, confirm every
   icon is colored (not gray — check `getComputedStyle` fill), reads as its component,
   and the set is cohesive. Do not trust the markup.

## Reuse for the flow editor

The flow node drawer/nodes can use the same Phosphor duotone glyphs (mapped per node
type, colored per node family) instead of emoji — same fetch + inline-style-fill rule.
