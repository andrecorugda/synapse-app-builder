---
name: component-icons
description: >
  Generate premium two-tone (duotone) SVG icons for Synapse block components that
  VISUALLY REFLECT what each component renders. Use when adding a new block, or
  refreshing/upgrading the icons in src/Blocks/Icons.php so the block palette looks
  like an expensive component library rather than generic outline glyphs.
---

# Synapse component icons — premium two-tone generator

Produce icons that (a) look like a paid, polished component library and (b) **depict
what the component actually renders** — a data table icon shows a table, a chart icon
shows bars, a hero icon shows a big headline + button. Never a generic square.

## Where icons live (the target)

`src/Blocks/Icons.php` → `private const GLYPHS = [ '<key>' => '<inner svg markup>', … ]`.

`Icons::for($key)` wraps the inner markup in this fixed frame — **do not repeat it**,
only produce the INNER markup:

```
<svg xmlns="…" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:26px;height:26px;">
  …YOUR INNER MARKUP…
</svg>
```

So every glyph is authored on a **24×24 grid**, single color via `currentColor`, and
adapts to the theme automatically.

## The two-tone system (this is what makes it look expensive)

Each icon is built from **two layers**, both using `currentColor`:

1. **TINT layer (the "mass")** — filled shapes representing the component's solid
   surfaces (a card, a header row, a bar, an input field). Author them explicitly:
   `fill="currentColor" stroke="none"` with `opacity="0.18"`–`0.28"` (default **0.22**).
2. **LINE layer (the "structure")** — the outlines/detail. These inherit the frame's
   `stroke="currentColor"` at full opacity (just draw strokes; no fill).

The result is a duotone glyph (soft tinted fills + crisp lines) in one adaptive color.
Rules:
- Keep ~2px optical padding inside the 24×24 box; center the mass.
- Use `rx` on rectangles (1–2) and the inherited rounded caps/joins — nothing sharp.
- Consistent metaphor + weight across the whole set (same corner radius, same bar
  gaps, same "card" look) — consistency is what reads as a designed system.
- One clear idea per icon. No more than ~2 tint shapes + a few lines. No clutter.
- Never rely on color other than `currentColor` (no hex fills) — it must work on the
  dark palette and any theme.

## Metaphor: the icon = the rendered output

Depict the component's real result. Reference concepts (extend for new blocks):

| key | what it renders → icon idea |
|---|---|
| data_table | header row (tinted) above 2–3 body row lines + a column divider |
| kpi | card outline + a big tinted number block + a short label line |
| chart | 3–4 tinted bars of varying height on a baseline |
| stats | same family as chart, thinner bars/ticks |
| form | 2 tinted input fields, each with a short label line above + a small submit chip |
| text_input / email_input / textarea | a single tinted field with label line (textarea = taller) |
| select / dropdown_menu | a tinted field with a ▾ chevron |
| record_picker | a small search line above a 2×2 grid of tinted tiles |
| autocomplete | a field + a tinted suggestion row dropping below |
| editable_grid | table with a tinted editable cell (caret) |
| stepper | −  [tinted number]  + |
| list | 3 rows each = a tinted bullet + a line |
| hero | big tinted headline bar + 2 thin subtext lines + a small solid button |
| navbar | top bar: a brand dot (tinted) + 3 nav dashes |
| footer | bottom bar (tinted) + link dashes |
| pricing | card + big tinted price + 2–3 tick lines |
| features | 2×2 tiles, each a tinted square + line |
| testimonial | speech bubble (tinted) + an avatar dot |
| team | 2 avatar heads (tinted circles) + shoulders |
| gallery / image | frame + tinted mountain + sun |
| cta | tinted panel + a solid button |
| faq / accordion | stacked bars, top one tinted/expanded with a chevron |
| card | a tinted card with a header strip + line |
| button | a single tinted rounded pill |
| modal / drawer | a dim tinted backdrop + a solid panel |
| chart/kpi/data widgets | keep the "data surface = tint, structure = line" language |

## Output contract

- Return ONLY the inner markup string (what goes inside `GLYPHS['<key>']`), single line,
  no `<svg>` wrapper, no XML declaration, no comments.
- Only `currentColor`; tint shapes carry `fill="currentColor" stroke="none" opacity="0.22"`,
  line shapes are plain strokes.
- Everything inside `0 0 24 24`. Validate: no coordinates <1 or >23.

## Applying to the package

1. For each `<key>`, generate the inner markup per the system above.
2. Edit `src/Blocks/Icons.php` — replace that key's entry in `GLYPHS`. Keep keys/order.
3. `php -l src/Blocks/Icons.php` (no syntax errors).
4. Verify visually in the GrapesJS block palette (the icons are the block "media"):
   open the page editor, screenshot the Sections/blocks panel, confirm each icon reads
   as its component and the set looks cohesive/premium. Do NOT trust the markup alone —
   render it. (Icons render on a dark palette, so tint at 0.22 should be clearly visible.)
5. Keep it lossless: `Icons::for()` and the GLYPHS shape are unchanged; only the markup
   values improve.

## Reference exemplars (correct two-tone style)

**data_table** — tinted header row + body lines:
```
<rect x="3" y="4" width="18" height="16" rx="2"/><rect x="3" y="4" width="18" height="4.5" fill="currentColor" stroke="none" opacity="0.22"/><line x1="3" y1="12.5" x2="21" y2="12.5"/><line x1="3" y1="16.5" x2="21" y2="16.5"/><line x1="10" y1="8.5" x2="10" y2="20"/>
```

**chart** — tinted bars on a baseline:
```
<rect x="4" y="12" width="3.5" height="6" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="10.25" y="8" width="3.5" height="10" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><rect x="16.5" y="5" width="3.5" height="13" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><line x1="3" y1="20" x2="21" y2="20"/>
```

**hero** — tinted headline + subtext lines + solid button:
```
<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="7" y="7" width="10" height="3" rx="1" fill="currentColor" stroke="none" opacity="0.22"/><line x1="8.5" y1="12" x2="15.5" y2="12"/><line x1="9.5" y1="14.2" x2="14.5" y2="14.2"/><rect x="9.5" y="16" width="5" height="2.4" rx="1.2" fill="currentColor" stroke="none" opacity="0.22"/>
```
