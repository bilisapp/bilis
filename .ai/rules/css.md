---
paths:
  - resources/css/app.css
---

# Css

## Three-level surface hierarchy in light mode

Light mode reads as three distinct levels and must stay that way: page `--background` hsl(225 20% 97%) < card/popover
hsl(0 0% 100%), with `--border` hsl(225 14% 89%) and `--input` hsl(225 13% 82%) clearly darker than every surface so
fields and hairlines are visible on a card. The sidebar rail sits *below* the page (`--sidebar` hsl(225 20% 95%)) so it
separates from the content area.

The ladder is achromatic (hue 225, low saturation), not the warm cream it used to be — anything hardcoding a
`hsl(44 …)` / `#f0ebdd` ground is stale. Public pages paint before the stylesheet lands, so the inline pre-paint style
and the `theme-color` metas in `resources/views/components/layouts/marketing.blade.php` must be kept equal to
`--background` in both modes; `tests/Feature/MarketingHeroTest.php` pins them.

Practical consequence: any panel that should read as a surface (toolbars, the log list, cards) needs `bg-card` — a bare `rounded-xl border` alone now sits at page level and disappears. shadcn inputs are `bg-transparent` in light mode, so they inherit the card's near-white and rely on `border-input` for definition.

## Magnitude is the third colour family, and it is not categorical
`--magnitude-1..4` encodes *how large a number is* (durations, latency) and nothing else. Severity and the chart slots are categorical — adjacent members differ by hue so they read as different things. Magnitude is the opposite: one hue walking from the neutral cast (225) toward violet at 280, separating by saturation and lightness, so four steps read as one scale. Its path is the one stretch of the wheel severity and the charts leave empty (41, 167, 214, 330, 354), which is why a tinted numeral can never be misread as a severity.

Thresholds are ABSOLUTE (50 ms / 500 ms / 5 s, in `magnitudeLevel()` in `resources/js/lib/traces.ts`), never relative to the rows on screen. A scale recomputed per result set paints the same 250ms trace cool on one screen and hot on the next, and a colour that means something different each time it is drawn still gets believed. Always go through `durationClass()`; never write `text-magnitude-*` by hand, and never build the class name from the level (Tailwind scans for whole class names).

Step 1 is the neutral cast, not a hue: most numbers in a healthy system are small, and a table where every row is tinted has spent the colour before reaching the row worth looking at. Never spend magnitude where a length already encodes the same quantity — a waterfall bar is duration drawn as size. Both modes are authored separately. See DESIGN.md, "The Magnitude Is Not A Category Rule".
