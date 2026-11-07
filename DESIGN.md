---
name: Bilis
description: Self-hostable log storage and search — an achromatic instrument where colour belongs to the data alone.
colors:
  page: "hsl(225 20% 97%)"
  surface: "hsl(0 0% 100%)"
  rail: "hsl(225 20% 95%)"
  ink: "hsl(225 18% 12%)"
  ink-muted: "hsl(225 9% 46%)"
  secondary: "hsl(225 14% 93%)"
  muted: "hsl(225 16% 95%)"
  accent: "hsl(225 16% 94%)"
  hairline: "hsl(225 14% 89%)"
  field-edge: "hsl(225 13% 82%)"
  ring: "hsl(225 12% 55%)"
  destructive: "hsl(2 72% 47%)"
  mark-gold: "#f3c440"
  mark-teal: "#45bfa6"
  mark-crimson: "#d8394a"
  mark-navy: "#1f3a5f"
  chart-1: "hsl(45 87% 46%)"
  chart-2: "hsl(167 48% 38%)"
  chart-3: "hsl(214 55% 38%)"
  chart-4: "hsl(354 64% 50%)"
  chart-5: "hsl(330 46% 52%)"
  page-dark: "hsl(225 14% 8%)"
  surface-dark: "hsl(225 13% 11%)"
  rail-dark: "hsl(225 15% 6%)"
  ink-dark: "hsl(225 16% 93%)"
  ink-muted-dark: "hsl(225 10% 62%)"
  secondary-dark: "hsl(225 11% 17%)"
  muted-dark: "hsl(225 11% 15%)"
  accent-dark: "hsl(225 11% 18%)"
  hairline-dark: "hsl(225 10% 20%)"
  field-edge-dark: "hsl(225 10% 27%)"
  ring-dark: "hsl(225 10% 58%)"
  destructive-dark: "hsl(2 76% 62%)"
  chart-1-dark: "hsl(45 85% 61%)"
  chart-2-dark: "hsl(167 50% 55%)"
  chart-3-dark: "hsl(214 62% 66%)"
  chart-4-dark: "hsl(354 72% 66%)"
  chart-5-dark: "hsl(330 62% 72%)"
  severity-trace: "hsl(225 8% 52%)"
  severity-debug: "hsl(167 45% 33%)"
  severity-info: "hsl(214 62% 42%)"
  severity-warn: "hsl(41 92% 33%)"
  severity-error: "hsl(354 66% 47%)"
  severity-fatal: "hsl(330 60% 42%)"
  severity-trace-dark: "hsl(225 8% 54%)"
  severity-debug-dark: "hsl(167 48% 52%)"
  severity-info-dark: "hsl(214 66% 66%)"
  severity-warn-dark: "hsl(45 85% 61%)"
  severity-error-dark: "hsl(354 74% 66%)"
  severity-fatal-dark: "hsl(330 72% 72%)"
typography:
  display:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 600
    lineHeight: "2.25rem"
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: "1.75rem"
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: "1.75rem"
    letterSpacing: "-0.025em"
  body:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: "1.25rem"
    letterSpacing: "normal"
  label:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: "1rem"
    letterSpacing: "normal"
  group-label:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 600
    lineHeight: "1rem"
    letterSpacing: "0.12em"
  data:
    fontFamily: "'Geist Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: "1rem"
    fontFeature: "tabular-nums"
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  full: "9999px"
spacing:
  hairline-gap: "6px"
  tight: "8px"
  row: "12px"
  panel: "16px"
  card: "24px"
  section: "48px"
components:
  button-primary:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.page}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-outline:
    backgroundColor: "{colors.page}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.lg}"
    padding: "24px 0"
  input:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "4px 12px"
    height: "36px"
    width: "100%"
  severity-chip:
    backgroundColor: "{colors.page}"
    textColor: "{colors.ink-muted}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: "4px 10px"
  severity-chip-active:
    backgroundColor: "{colors.secondary}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: "4px 10px"
  nav-item:
    backgroundColor: "transparent"
    textColor: "{colors.ink-muted}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px"
  nav-item-active:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px"
  log-row:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    typography: "{typography.data}"
    rounded: "0"
    padding: "6px 12px"
---

# Design System: Bilis

## Overview

**Creative North Star: "The Achromatic Instrument"**

Bilis has exactly one idea about colour: it belongs to data, and the interface around the data does not get any. Every surface, border, button, focus ring, icon, nav item and piece of type is cut from a single neutral ladder. Only three families carry hue, and all three are data — the six severity levels, the five chart series, and the four magnitude steps. The first two are drawn from the three stripes in the mark's tail, so the palette has a visible origin: point at the logo and you are pointing at the severity ramp. The third is the exception that proves the rule — it starts at the neutral cast itself and walks away from it, because it measures rather than names. There is no brand accent, no primary blue, no coloured call to action; that absence is the design, not a shortage of one.

The reason is the job. Somebody sits in front of a log stream at two in the morning scanning thousands of monospace lines for the one that explains the outage. Anything chromatic in that field is a claim on attention. If the primary button is blue and the nav highlight is indigo and the logo is gold, then a red `ERROR` is competing with three things that mean nothing. Strip the interface to grey and the ramp becomes the only signal in the room.

The neutrals are not dead grey. They all carry the same faint cool cast — hue 225 at 8–20% saturation — so they read as one material rather than a pile of unrelated greys, without ever reading as blue. Dark is the designed-for mode; light is authored properly, not derived. Density is high, corners are modest, borders are hairlines, and nothing rests on a shadow.

**Key Characteristics:**

- Colour belongs to data. The chrome is built from one achromatic ladder; the severity ramp and the chart series are the only things with hue, and both come from the mark's tail.
- The primary action is filled with ink, never with an accent hue.
- Cool-cast neutrals (hue 225) so greys read as one material, never as blue.
- Geist for what the interface says, Geist Mono for what a machine wrote.
- Flat at rest — depth is a response to state, never a resting property.
- Dark-first: the mode this product is actually read in.
- Every reusable component appears in `/styleguide`. A component that isn't there isn't done.

## Colors

An achromatic chrome with two chromatic data families. Defined once in `resources/css/app.css`.

### Primary

- **Ink** (`hsl(225 18% 12%)` light / `hsl(225 16% 93%)` dark): the primary action and the body text, the same value doing both jobs. A filled primary button is ink on the page in light mode and near-white on the page in dark. There is no primary *colour* in this product.

### Secondary

- **Secondary** (`hsl(225 14% 93%)` / `hsl(225 11% 17%)`): quiet buttons and the fill of an active severity chip.
- **Accent** (`hsl(225 16% 94%)` / `hsl(225 11% 18%)`): the pointer response only — row hover, menu highlight, ghost button fill. It is the one fill that appears purely because a cursor is present.

### Tertiary

- **Destructive** (`hsl(2 72% 47%)` / `hsl(2 76% 62%)`): delete team, revoke API key, error alerts. The single warm hue permitted outside the ramp, because it warns about an *action* the reader is about to take rather than describing a log they are reading.

### Neutral

- **Page** (`hsl(225 20% 97%)` / `hsl(225 14% 8%)`): the ground the content panel sits on.
- **Surface** (`hsl(0 0% 100%)` / `hsl(225 13% 11%)`): every panel that must read as a surface — toolbar, volume strip, log list.
- **Rail** (`hsl(225 20% 95%)` / `hsl(225 15% 6%)`): the navigation rail, one step *below* the page in both modes so navigation separates from work without needing a colour to do it.
- **Muted Ink** (`hsl(225 9% 46%)` / `hsl(225 10% 62%)`): timestamps, service names, row counts, helper text — everything that supports content rather than being it.
- **Hairline** (`hsl(225 14% 89%)` / `hsl(225 10% 20%)`) and **Field Edge** (`hsl(225 13% 82%)` / `hsl(225 10% 27%)`): borders and input strokes. Field edge is deliberately darker so a transparent input still reads as an input on a surface.
- **Ring** (`hsl(225 12% 55%)` / `hsl(225 10% 58%)`): keyboard focus. A neutral step, never a hue — focus is a shape and a weight here.

### The mark's tail — where the data colours come from

Both data palettes are drawn from one place: the three stripes in the Bilis mark's tail, plus its navy body. `--color-mark-gold` (`#f3c440`), `--color-mark-teal` (`#45bfa6`), `--color-mark-crimson` (`#d8394a`), `--color-mark-navy` (`#1f3a5f`).

These are **not interface colours** — nothing in the chrome may use them — but they give the palette a visible origin rather than an arbitrary one. Point at the logo and you are pointing at the severity ramp.

### Severity

Six hand-tuned values per mode, exposed as `--severity-{trace,debug,info,warn,error,fatal}` and the `text-severity-*` / `bg-severity-*` utilities. The ramp is the tail read in severity order:

- **trace** — achromatic grey. Sits *below* the ramp; the quietest level gets no hue at all.
- **debug** — the tail teal. The coolest hue in the ramp.
- **info** — the tail navy, opened up to a readable blue. The anchor the rest is read against.
- **warn** — the tail gold.
- **error** — the tail crimson.
- **fatal** — crimson pushed toward magenta. A *hue away* from error, not a darker shade of it, so the two loudest levels can never be confused at a glance.

### Named Rules

**The Chrome Is Achromatic Rule.** Colour belongs to data; chrome never gets any. Surfaces, borders, buttons, focus rings, nav, icons and type are built entirely from the neutral ladder — there is no accent colour, no brand hue, no coloured primary button, no coloured link. Anything that needs emphasis and is not *data* gets a neutral step, a weight change, or a fill. Destructive is the one stated exception, and it warns about an action the reader is about to take rather than decorating the interface.

**The Data Gets Colour Rule.** Three families are allowed hue, because all three *are* data: the severity ramp, the five categorical chart series, and the four magnitude steps. Severity and the chart series are drawn from the mark's tail, and both are ordered so adjacent members separate by hue rather than by saturation.

**The Magnitude Is Not A Category Rule.** `--magnitude-1..4` encodes *how large a number is* — a duration, a latency — and nothing else. It is the one family that is not categorical, and its form says so: a single hue walking from the neutral cast at 225 toward violet, separating by saturation and lightness rather than by hue, so four steps read as one scale. It runs through the one stretch of the wheel severity and the chart slots leave empty (41, 167, 214, 330, 354), so a tinted numeral can never be misread as a severity. Its thresholds are absolute, never relative to what is on screen: a scale recomputed per result set paints the same 250ms trace cool on one screen and hot on the next, and a colour that means something different each time it is drawn still gets believed. Step 1 is the neutral cast rather than a hue, because most numbers in a healthy system are small and a table where every row is tinted has spent the colour before reaching the row worth looking at. Never spend it where a length already encodes the same quantity — a waterfall bar is duration drawn as size, and colouring it by duration too is one fact wearing two costumes.

**The Reserved Ramp Rule.** The six severity values mean severity and nothing else. No badge, chip, illustration, chart series, or decorative accent may borrow one for another meaning — and a severity chart reads `--severity-*` directly rather than approximating it from the chart palette.

**The One Cast Rule.** Every neutral carries hue 225 at 8–20% saturation. A neutral mixed from another hue, or a dead 0%-saturation grey, breaks the material and reads as a mistake beside the others.

**The Twice-Tuned Rule.** Every value that appears in both modes is authored twice. Never derive a dark value by fading, lightening, or applying opacity to its light counterpart.

## Typography

**Interface Font:** Geist (with `ui-sans-serif, system-ui, sans-serif`)
**Data Font:** Geist Mono (with `ui-monospace, SFMono-Regular, Menlo, monospace`)

Both are self-hosted through the Vite font plugin (`bunny()` in `vite.config.ts`); nothing is fetched from a third party at runtime.

**Character:** One family, two voices. Geist is a workhorse UI face that stays even and legible at 12px, which is where most of this product lives. Geist Mono is drawn for exactly this job — digits, identifiers and punctuation that must stay distinct in a stream of timestamps. The split between them is the whole type system.

**User preference.** Font is a per-account setting (Settings -> Appearance), not fixed by the theme: IBM Plex Mono is available as an alternate face for both roles, self-hosted alongside Geist. Geist / Geist Mono remains the default.

### Hierarchy

The ramp is named in `app.css` (`@theme`), so Vue and Blade say the same thing — `text-display`, not `text-7xl tracking-[-0.04em]`. The Paper "Design system — Bilis" board draws it.

- **`text-display`** (600, `4.5rem`/`1`, `-0.04em`): the public hero. One per site page; never in the app.
- **`text-headline`** (600, `3rem`/`1.08`, `-0.04em`): a public section's headline.
- **`text-title`** (600, `1.875rem`/`2.25rem`, `-0.025em`): the app's page title (`PageHeader`), a KPI figure, a public figure.
- **`text-lead`** (`1rem`/`1.625rem`): section headings in the app (semibold, `tracking-tight`); lead copy on public pages.
- **`text-body`** (400, `0.875rem`/`1.25rem`): all interface prose. The default; descriptions cap around 65–75 characters.
- **`text-caption`** (`0.75rem`/`1rem`): support copy, and Geist Mono data — log rows, attribute pairs, ids, with `tabular-nums` on timestamps.
- **`text-label`** (500, `0.6875rem`/`1rem`): badges, keys, and — with `tracking-label` (`0.08em`) and uppercase — eyebrows and column headers.

Three pieces have a utility of their own, because they recur everywhere: **`eyebrow`** (the uppercase line above a title), **`column-header`** (a list's uppercase header row on a faint muted fill) and **`kbd`** (a keyboard key). Spacing has two named steps: **`gutter`** (`2.5rem`, the one left edge every band shares) and **`section`** (`6rem`, between public sections); **`max-w-content`** (`70rem`) is the public column.

### Named Rules

**The Monospace Boundary Rule.** Monospace marks machine-authored text. Log bodies, timestamps, ids and attribute pairs are Geist Mono; every label *about* them ("Log attributes", "Showing 1–50 of 12,481 rows") drops back to Geist. The typeface change is how a reader tells the record from the annotation.

**The 14/12 Rule.** In the app, interface copy is 14px and support copy is 12px; 16px is a heading, never a body. Public pages may set lead copy at 16px. There is no 13px anything.

**The Tabular Timestamp Rule.** Any column of digits a reader scans vertically is `tabular-nums`. Digits that shift width break the scan.

## Layout

A fixed navigation rail plus one white work surface (`bg-card`, a hairline ring, inset from the rail); the log viewer is the archetype. The shell is viewport-height (`h-svh` on the sidebar provider) and the *page* scrolls inside the surface, never the document — which is what keeps the log stream's own header fixed while a reader scrolls thousands of rows.

Pages sit **directly on the surface**, not in cards. Every page reads top to bottom as the same bands, all sharing one left edge — the gutter `px-4 md:px-10`:

1. **Page header** (`PageHeader.vue`): a small uppercase eyebrow (the breadcrumb trail, or the scope — `All projects / All services`), one `text-3xl` title, one summary sentence of numbers in which only the figure that matters takes a colour, and the actions on the right (the primary action in ink, tabs as `SegmentedControl`).
2. **Toolbar**: one row — search with a `/` hint, then filters as chips (`FilterSelect.vue`: "Service  All ⌄"), then sort and reset.
3. **Volume band** (optional): a caption line (`Events per hour · context`, `peak N` right) and a bar chart; a legend that also filters sits at the right of the caption.
4. **List**: a hairline, an uppercase `11px` column header on `bg-muted/40`, rows ruled by hairlines with fixed-width numeric lanes, and a footer with counts on the left and keyboard hints on the right.

Sections inside a page are headed by a `text-base` semibold title over a hairline, not wrapped in a card. A card is kept only for a genuinely separate object — a code snippet, a dialog, a callout.

Spacing runs on a 4px base, but really uses six steps: `6px` label-to-field, `8px` tight inline, `12px` row padding and control gaps, `16px` panel padding, `24px` card interiors, `48px` between sections.

The log stream is the one place density overrides rhythm: rows are `6px 12px` with fixed-width columns — timestamp, a 64px severity column, a 160px truncated service — and the message takes the rest.

**Public pages speak the same language.** The landing page, features, pricing and docs are Blade, but they are assembled from `resources/views/components/ui` — `x-ui.eyebrow`, `x-ui.section-header`, `x-ui.button`, `x-ui.badge`, `x-ui.figure`, `x-ui.panel` — cut from the same tokens as the app's Vue components. A public section opens with a numbered eyebrow, a headline and a lead over a hairline; the product is *shown* in the app's own components (an issue list, a waterfall, a live tail) rather than in screenshots; figures sit on hairlines like the dashboard's KPI strip; a card is used only for a piece of product or the closing call to action.

### Named Rules

**The Surface Declaration Rule.** A `rounded-lg border` alone sits at page level and disappears. Anything that should read as a surface must declare `bg-card`. In the app the work surface already is one, so a page's own sections declare nothing and separate by hairline.

**The Fixed Column Rule.** In the log stream every column left of the message has a fixed width. Nothing shifts between rows.

**The Shell Owns The Scroll Rule.** The document never scrolls in the app. A scroll container must have a definite-height ancestor and be a containing block (`relative`), or absolutely positioned descendants escape its clip and hand scrolling back to the document.

## Elevation & Depth

Depth comes from tone and hairlines, not from shadow. Both modes run a ladder — rail below page below surface — with borders darker than every surface. Surfaces are flat at rest; shadow appears only when something is hovered, focused, or genuinely floating (dialogs, dropdowns, popovers, sheets).

### Shadow Vocabulary

- **Overlay** (`0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)`): things that float above the page.
- **Focus ring** (`0 0 0 3px color-mix(in oklab, var(--ring) 50%, transparent)`): paired with a border shift to `--ring`.

### Named Rules

**The Flat-At-Rest Rule.** If an element's state has not changed, it casts no shadow. Structure is carried by tone and hairline.

## Shapes

A single `--radius: 0.5rem` seeds a three-step scale — `4px` / `6px` / `8px`. Buttons and inputs are `6px`; cards, toolbar and log list are `8px`; chips, badges and severity dots are fully round. Nothing is sharp-cornered and nothing is heavily rounded: instrument chrome, not stationery.

Borders are always `1px`. The severity dot — an `8px` circle at full opacity when active, `40%` when not — is the system's smallest and most repeated shape.

### Named Rules

**The Hairline-Only Rule.** Borders are `1px`. To emphasise an element, change its fill or its tone; never thicken its stroke.

## Components

### Buttons

- **Shape:** `6px` radius, `36px` tall by default, `16px` horizontal padding.
- **Primary:** `bg-primary` with `text-primary-foreground` — ink on light, near-white on dark. Never a coloured fill.
- **Hover / Focus:** primary drops to 90% opacity; focus-visible shifts the border to `--ring` and adds a `3px` ring at 50%.
- **Outline:** hairline on the page ground, accent fill on hover.
- **Ghost:** no fill at rest, accent fill on hover. The default for icon-only controls and for the toolbar's history controls.
- **Destructive:** the one warm fill in the system.

### Navigation

- **Rail:** one tone below the page, holding the Bilis mark and the rail's collapse toggle at the top, then Dashboard on its own and three named groups — **Explore** (Logs, Traces, Metrics), **Triage** (Issues, Autofix), **Setup** (Projects, Docs ↗) — and one account card at the foot (team tile, team name, your address; one menu for switching team, settings and log out). Team and account share it because both answer "who am I acting as". Sections with two views (Traces, Metrics) keep them as tabs on the page, not as a sub-menu.
- **Active item:** takes the *work surface* as its fill (`bg-sidebar-primary`) with a hairline ring and ink, so the current page is legible from the rail's silhouette before a word is read — no shadow. Hover stays a quiet accent lift, which keeps the two states distinct.
- **Counts:** an item may carry a neutral mono count (Issues: unresolved in the last 7 days). Its one dot of `--severity-error` appears only when something regressed.
- **Group labels:** `11px`, `600`, uppercase, `0.12em` tracking, at 70% of the rail foreground.

### Severity Filter

The six-level filter is the log volume chart's legend (`LogsSeverityFilter.vue`): the key to the bar colours and the control that narrows them are one thing.

- `12px` capitalised labels, each with a `8px` square swatch in its severity colour.
- **No level picked** means every level, every swatch at full strength. **Picked:** muted fill and `font-medium`; the unpicked fade to 30%.
- `aria-pressed` carries the state; opacity plus weight mean the toggle never depends on colour alone.

### Cards / Containers

`8px` radius, `bg-card`, a single hairline, flat at rest, `24px` interiors — and rare. In the app the page itself is the surface; a card is for a genuinely separate object (a snippet block, a callout), never a wrapper around a page's own section.

### Inputs / Fields

`36px` tall, `6px` radius, `bg-transparent` with a `border-input` stroke. Focus shifts the border to `--ring` plus a `3px` ring. `aria-invalid` turns border and ring destructive. Labels are always present at `12px` muted.

### Log Entry Row

The signature component; everything else frames it.

- Full-width button row (Geist Mono, `12px`) on the page gutter, plus an expandable detail panel aligned to the service column. Fixed columns: the time of day (`HH:MM:SS.mmm`, `tabular-nums`, UTC on hover), a `44px` uppercase level in the severity colour, a `128px` truncated service, then the message — an exception class at its head (`TypeError:`) set in semibold. The date is said once per day, as a divider in the stream, not on every row.
- Every row carries a **2px severity stripe on its left edge**, so a stack of rows reads as a temperature ribbon before a single line is read. The open or cursored row widens it to 3px.
- **No resting wash.** The stripe and the level's name carry severity; the row itself stays on the surface, so the stream reads as text rather than as a field of pink.
- Separator is a bottom hairline. Rows are ruled lines, not stacked cards.
- Rows arriving from a live-tail poll animate in once, `motion-reduce` guarded.

### Charts

Apache ECharts only, always through `ChartCanvas.vue`, always themed from CSS custom properties via `useChartTokens()`. `--chart-1..5` is the mark's tail in order — gold, teal, navy, crimson, and crimson-toward-magenta so a five-series chart never ends on two reds — authored twice so it holds on both the near-white and the dark card. A **severity** chart ignores that palette and reads `--severity-*` directly, so a bar and the rows beneath it always agree on what "error" looks like.

## Do's and Don'ts

### Do:

- **Do** build every non-severity element from the neutral ladder — surfaces, borders, buttons, focus rings, icons, type.
- **Do** give every panel that should read as a surface `bg-card`.
- **Do** reference semantic tokens (`bg-primary`, `text-muted-foreground`, `border-border`) rather than raw values.
- **Do** author every value twice — a hand-tuned light value and a hand-tuned dark value.
- **Do** pair severity colour with the dot *and* the uppercase label. Colour never carries severity alone.
- **Do** use `tabular-nums` on any column of digits scanned vertically.
- **Do** keep Geist Mono for machine-authored text and Geist for everything the interface says about it.
- **Do** add every new reusable component to `/styleguide` in the same change.
- **Do** keep borders at `1px` and create emphasis with fill or tone.

### Don't:

- **Don't** introduce an accent colour, a brand hue, a coloured primary button, or a coloured link. Colour is for data; spending it on chrome costs the severity ramp its meaning.
- **Don't** borrow a severity colour for anything that isn't log severity.
- **Don't** use a dead 0%-saturation grey or a neutral mixed from another hue — every neutral is hue 225.
- **Don't** derive a dark-mode value by fading or lightening its light counterpart.
- **Don't** put a resting shadow on a surface.
- **Don't** let column widths vary between log rows.
- **Don't** introduce a third interface type size. 14px body and 12px support carry the product.
- **Don't** hardcode a chart colour or `import from 'echarts'` — go through `lib/echarts.ts` and `useChartTokens()`.
- **Don't** let the document scroll in the app shell; the content column owns the scroll.
