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
