---
paths:
  - resources/css/app.css
---

# Css

## Three-level surface hierarchy in light mode
Light mode reads as three distinct levels and must stay that way: page `--background` hsl(44 33% 93%) < card/popover hsl(44 50% 98%) (near-white warm), with `--border` hsl(42 18% 72%) and `--input` hsl(42 18% 65%) clearly darker than every surface so fields and hairlines are visible on a card. The sidebar rail sits *below* the page (`--sidebar` hsl(44 26% 87%)) so it separates from the content area.

Practical consequence: any panel that should read as a surface (toolbars, the log list, cards) needs `bg-card` — a bare `rounded-xl border` alone now sits at page level and disappears. shadcn inputs are `bg-transparent` in light mode, so they inherit the card's near-white and rely on `border-input` for definition.
