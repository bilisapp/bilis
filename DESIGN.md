---
name: Bilis
description: Self-hostable log storage and search — a warm, quiet reading room for dense machine text.
colors:
  cream: "#f3f0e7"
  greige: "#dbd5c3"
  espresso: "#463828"
  navy: "#1f3a5f"
  gold: "#f3c440"
  crimson: "#d8394a"
  teal: "#45bfa6"
  aqua: "#abdcd2"
  blush: "#f3b9b3"
  page: "hsl(44 33% 93%)"
  surface: "hsl(44 50% 98%)"
  ink: "hsl(30 27% 17%)"
  ink-muted: "hsl(30 12% 40%)"
  primary: "hsl(215 51% 25%)"
  primary-foreground: "hsl(44 40% 95%)"
  secondary: "hsl(43 28% 84%)"
  muted: "hsl(44 26% 88%)"
  accent: "hsl(45 74% 86%)"
  destructive: "hsl(354 64% 51%)"
  hairline: "hsl(42 18% 72%)"
  field-edge: "hsl(42 18% 65%)"
  rail: "hsl(44 26% 87%)"
  page-dark: "hsl(28 22% 9%)"
  surface-dark: "hsl(28 20% 12%)"
  ink-dark: "hsl(44 30% 90%)"
  ink-muted-dark: "hsl(40 15% 63%)"
  primary-dark: "hsl(45 85% 61%)"
  primary-foreground-dark: "hsl(28 30% 12%)"
  secondary-dark: "hsl(28 15% 19%)"
  muted-dark: "hsl(28 15% 17%)"
  accent-dark: "hsl(28 15% 22%)"
  destructive-dark: "hsl(354 70% 58%)"
  hairline-dark: "hsl(28 14% 24%)"
  field-edge-dark: "hsl(28 14% 30%)"
  rail-dark: "hsl(28 24% 7%)"
  severity-trace: "hsl(30 10% 55%)"
  severity-debug: "hsl(166 25% 42%)"
  severity-info: "hsl(215 51% 38%)"
  severity-warn: "hsl(42 90% 40%)"
  severity-error: "hsl(354 64% 48%)"
  severity-fatal: "hsl(354 75% 32%)"
  severity-trace-dark: "hsl(40 10% 55%)"
  severity-debug-dark: "hsl(166 35% 55%)"
  severity-info-dark: "hsl(215 55% 65%)"
  severity-warn-dark: "hsl(45 85% 61%)"
  severity-error-dark: "hsl(354 75% 63%)"
  severity-fatal-dark: "hsl(354 85% 72%)"
typography:
  display:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 600
    lineHeight: "2.25rem"
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: "1.75rem"
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: "1.75rem"
    letterSpacing: "-0.025em"
  body:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: "1.25rem"
    letterSpacing: "normal"
  label:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: "1rem"
    letterSpacing: "normal"
  data:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: "1rem"
    fontFeature: "tabular-nums"
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
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
    backgroundColor: "{colors.primary}"
    textColor: "{colors.primary-foreground}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-primary-hover:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.primary-foreground}"
  button-outline:
    backgroundColor: "{colors.page}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-outline-hover:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.ink}"
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
    rounded: "{rounded.xl}"
    padding: "24px 0"
  input:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "4px 12px"
    height: "36px"
    width: "100%"
  badge:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.primary-foreground}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: "2px 8px"
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
  log-row:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    typography: "{typography.data}"
    rounded: "0"
    padding: "6px 12px"
---

# Design System: Bilis

## Overview

**Creative North Star: "The Reading Room"**

Bilis is a place built for long sessions with dense machine text. Somebody is going to sit in front of a log stream at two in the morning, scanning thousands of monospace lines for the one that explains the outage. Every decision in this system serves that person's eyes over that duration. Chrome recedes; content holds the contrast. The rhythm is generous where it costs nothing and tight where density earns its keep.

The character is **utilitarian with a warm accent**. Function comes first and says so — hairline borders, modest radii, no ornament that isn't doing work. The mid-century palette is the one concession to character, and it is spent deliberately: a cream page instead of a white one, espresso instead of black, navy and gold instead of a generic blue. That warmth is not decoration, it is ergonomics. Cream at 93% lightness and espresso ink at 17% is a gentler pairing to read for an hour than pure black on pure white, and the system's entire reason for choosing it is that the reading session is long.

Light and dark are two designed worlds, not one world with a filter over it. The primary voice actually changes between them — navy in light, gold in dark — and every severity colour is hand-tuned twice. Dark mode is where this product is most often used, and it is authored, not derived.

**Key Characteristics:**

- Cream page, near-white surfaces, espresso ink — warm neutrals all the way down, never a pure white or a pure black.
- A strict three-level surface ladder in light mode: page < card < hairline.
- Colour is rationed. Severity is the only thing on screen allowed to be chromatic for its own sake.
- Monospace is reserved for log data; the interface around it is Instrument Sans.
- Flat at rest. Depth is a response to state, not a resting property.
- Every reusable component appears in `/styleguide`. A component that isn't there isn't done.

## Colors

Warm neutrals carrying a small, disciplined set of signal colours — the palette is drawn from a mid-century stripes artwork and defined once in `resources/css/app.css`.

### Primary

- **Deep Ledger Navy** (`hsl(215 51% 25%)`): the light-mode primary. Filled buttons, focus rings, the sidebar logo tile, the active link. Dark enough to carry white text at full AA on the cream page, saturated enough to read as a decision rather than a default.
- **Signal Gold** (`hsl(45 85% 61%)`): the dark-mode primary, and the same value as the dark-mode `warn` severity. On espresso it is the brightest thing available, so it takes over every job navy does in light mode.

### Secondary

- **Warm Greige** (`hsl(43 28% 84%)` light / `hsl(28 15% 19%)` dark): secondary buttons, the active severity chip, and any fill that needs to read as selected without shouting. It is the palette's `greige` (`#dbd5c3`) tuned per mode.
- **Pale Gold Wash** (`hsl(45 74% 86%)` light / `hsl(28 15% 22%)` dark): the accent — hover fills on ghost buttons, log-row hover, dropdown item highlight. It is the only fill that appears purely as a response to the pointer.

### Tertiary

- **Crimson** (`#d8394a`, semantic `hsl(354 64% 51%)`): destructive actions and the error severity. Never used decoratively.
- **Teal, Aqua, Blush** (`#45bfa6`, `#abdcd2`, `#f3b9b3`): the remaining brand stripes. They exist for deliberate brand moments and for chart series, not for UI state. Teal appears as `chart-2` and as the light-mode `debug` severity.

### Neutral

- **Cream Page** (`hsl(44 33% 93%)`, brand `#f3f0e7`): the application background. The floor of the light-mode ladder.
- **Warm Near-White** (`hsl(44 50% 98%)`): cards, popovers, and every panel that must read as a surface. It is not white; it carries the same warmth as the page, two steps up.
- **Espresso Ink** (`hsl(30 27% 17%)`, brand `#463828`): body text in light mode. A brown-black, never `#000`.
- **Muted Ink** (`hsl(30 12% 40%)` light / `hsl(40 15% 63%)` dark): timestamps, service names, row counts, helper text, and every label that supports content rather than being it.
- **Hairline** (`hsl(42 18% 72%)` light / `hsl(28 14% 24%)` dark) and **Field Edge** (`hsl(42 18% 65%)` light / `hsl(28 14% 30%)` dark): borders and input strokes. Field edge is deliberately darker than hairline so a `bg-transparent` input stays visible on a near-white card.
- **Deep Espresso Ground** (`hsl(28 22% 9%)`) and **Rail** (`hsl(28 24% 7%)` dark / `hsl(44 26% 87%)` light): dark page and the sidebar rail. Note the inversion — in light mode the rail sits *below* the page; in dark mode it sits below as well, and in both cases the content area is the brighter of the two.

### Severity

Six hand-tuned values per mode, exposed as `--severity-{trace,debug,info,warn,error,fatal}` and the `text-severity-*` / `bg-severity-*` utilities.

- **trace** — neutral warm grey. Present, unremarkable.
- **debug** — muted teal. Cool and quiet.
- **info** — navy in light, a lifted blue in dark. The baseline.
- **warn** — amber; in dark mode it *is* the primary gold.
- **error** — crimson.
- **fatal** — the deepest crimson in light, the brightest in dark. In both modes it is the extreme end of the ramp, never a mid-tone.

The ramp is engineered to read as temperature before it reads as hue: cool and low-chroma at trace/debug, warm and loud at warn/error/fatal.

### Named Rules

**The Two Primaries Rule.** Navy is the light-mode voice; gold is the dark-mode voice. Both are first-class and neither is derived from the other. Always reference `--primary` / `bg-primary`; never hardcode either hex, and never assume the primary is dark — in dark mode it is the brightest thing on screen and requires `--primary-foreground` (espresso) on top of it.

**The Reserved Ramp Rule.** The six severity colours mean severity and nothing else. No badge, chart series, chip, illustration, or decorative accent may borrow a severity value for a non-severity meaning. When a UI needs a colour and it isn't communicating log severity, it takes one from the semantic tokens or the brand stripes.

**The No Pure Values Rule.** No `#fff`, no `#000`, no neutral grey with zero chroma anywhere in the interface. Every neutral carries warmth. A cool grey next to cream reads as a mistake.

**The Twice-Tuned Rule.** Every colour that appears in both modes is authored twice. Never derive a dark-mode value by fading, lightening, or applying opacity to its light-mode counterpart.

## Typography

**Display / Body Font:** Instrument Sans (with `ui-sans-serif, system-ui, sans-serif`)
**Data Font:** the platform monospace stack (`ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace`)

**Character:** One humanist sans doing all the interface work, and a system monospace doing all the data work. Instrument Sans is slightly condensed and even in colour, so headings stay tight at `-0.025em` tracking without feeling cramped, and 14px body copy holds up against a dense table. The split is strict and it is the type system's whole idea: if it came out of a log, it's monospace; if the interface is saying it, it's Instrument Sans.

> **Under evaluation:** IBM Plex Sans and IBM Plex Mono are loaded alongside Instrument Sans as candidates (`--font-plex-sans`, `--font-plex-mono`), compared on the `/styleguide` Fonts section. Instrument Sans is the current default. Remove the losing stack once the decision lands.

### Hierarchy

- **Display** (600, `1.875rem`/`2.25rem`, `-0.025em`): page-level titles. One per page, at most.
- **Headline** (600, `1.25rem`/`1.75rem`, `-0.025em`): section headings and page headers (`Heading.vue` default, `SectionShell` titles).
- **Title** (600, `1.125rem`/`1.75rem`, `-0.025em`): subsection headings.
- **Body** (400, `0.875rem`/`1.25rem`): all interface prose. The default. Descriptions cap at roughly `max-w-3xl` (~65–75 characters).
- **Label** (500, `0.75rem`/`1rem`): form labels, toolbar labels, chip text, row counts, helper text. Almost always paired with muted ink.
- **Data** (400, `0.75rem`/`1rem`, monospace, `tabular-nums` on timestamps): log rows, attribute keys and values, trace and span ids, expanded bodies.

### Named Rules

**The Monospace Boundary Rule.** Monospace marks machine-authored text. Log bodies, timestamps, ids, and attribute pairs are monospace; every label *about* them ("Log attributes", "Resource attributes", "Showing 1–50 of 12,481 rows") drops back to Instrument Sans. The typeface change is how a reader tells the record from the annotation.

**The 14/12 Rule.** Interface copy is 14px and support copy is 12px. There is no 16px body and no 13px anything. Two sizes carry ninety percent of the product, which is what lets a dense table and its surrounding chrome share one rhythm.

**The Tabular Timestamp Rule.** Any column of numbers a reader scans vertically — timestamps above all — is `tabular-nums`. Digits that shift width break the scan.

## Layout

The application is a fixed sidebar rail plus a content column; the log viewer is the archetype. Content sits in a `px-4 py-6` field with sections separated by `48px` (`space-y-12`) and card interiors at `24px`. Panels — the toolbar, the log list, cards — are `rounded-xl` (12px) with a hairline border and `bg-card`.

Spacing runs on Tailwind's 4px base, but the system really uses six steps: `6px` between a label and its field, `8px` for tight inline gaps, `12px` for row padding and control gaps, `16px` for panel padding, `24px` for card interiors, and `48px` between page sections.

The log stream is the one place density overrides rhythm. Rows are `6px 12px` with fixed-width columns — timestamp, a 64px severity column, a 160px truncated service name — and the message takes all remaining width with `truncate` until the row is expanded. Column widths are fixed on purpose: the reader's eye lands in the same place on every row, and a ragged left edge on the message column would destroy the scan.

Responsive behaviour is straightforward: control clusters in the toolbar are `flex-wrap` and reflow rather than collapsing into a menu; definition grids go from one column to `sm:grid-cols-2`; the styleguide demo grid goes to `lg:grid-cols-2`. The log list scrolls horizontally inside its own container rather than letting the page scroll sideways.

### Named Rules

**The Surface Declaration Rule.** A `rounded-xl border` alone sits at page level and disappears. Any element that should read as a surface must declare `bg-card`. This is the practical consequence of the three-level ladder and it is the single most common way to break light mode.

**The Fixed Column Rule.** In the log stream, every column left of the message has a fixed width. Nothing shifts between rows.

## Elevation & Depth

Depth comes from tone, not from shadow. The light mode is a three-level ladder — page `hsl(44 33% 93%)` below card `hsl(44 50% 98%)` below the hairline and field-edge strokes that are darker than every surface — and that ladder alone is expected to communicate structure. The sidebar rail sits below the page on both sides of the ladder so the content area separates from the chrome. Dark mode runs the same structure with espresso tones and leans even harder on tone plus border, because shadows are close to invisible on a `9%`-lightness ground.

**Shadows are a response to state, not a resting property.** A surface at rest is flat. Shadow appears when something is hovered, focused, or floating above the page — dropdowns, dialogs, popovers, sheets. It is a signal that an element is temporarily lifted or temporarily interactive, and if nothing has changed state, nothing should be casting.

> **Known drift:** `Card.vue`, `LogsToolbar.vue`, and several shadcn primitives currently carry a resting `shadow-sm` / `shadow-xs`. That is the incumbent code, not the doctrine above. Removing resting shadows in favour of the tonal ladder plus hairline is an open cleanup.

### Shadow Vocabulary

- **State lift** (`box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05)`): the hover/interactive whisper. Never at rest.
- **Overlay** (`box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)`): dialogs, dropdowns, popovers, sheets — things that genuinely float.
- **Focus ring** (`0 0 0 3px var(--ring) / 50%`): not elevation, but the same family of state response. Always `ring-[3px]` with a `border-ring` shift; never an outline suppression without a replacement.

### Named Rules

**The Flat-At-Rest Rule.** If an element's state has not changed, it casts no shadow. Structure is carried by tone and hairline. A shadow on a resting card is borrowed depth the ladder already provides.

## Shapes

Corners are modest and consistent. A single `--radius: 0.5rem` seeds a three-step scale — `4px` (sm), `6px` (md), `8px` (lg) — with `12px` (`rounded-xl`) for large panels and full-round reserved for things that are conceptually pills. Buttons and inputs are `6px`. Cards, the toolbar, and the log list are `12px`. Chips, badges, and the severity dots are fully round.

Nothing is sharp-cornered and nothing is heavily rounded. The form language is a well-made instrument: softened enough to feel considered, square enough to feel precise.

Borders are hairlines — always `1px`, always the `border` token, never a heavier stroke to create emphasis. Emphasis comes from fill or from tone, not from border weight.

The severity dot is the system's smallest and most repeated shape: an `8px` circle at full opacity when active, `40%` when not. It appears in the log row, in the toolbar's filter chips, and in the styleguide's severity section — the same object in every context.

### Named Rules

**The Hairline-Only Rule.** Borders are `1px`. To emphasise an element, change its fill or its tone; never thicken its stroke.

## Components

### Buttons

Tactile and confident — solid fills, decisive hovers, components that feel pressable.

- **Shape:** gently rounded (`6px`), `36px` tall at default size, `32px` small, `40px` large, with `16px` horizontal padding that tightens to `12px` when the button holds an icon.
- **Primary:** `bg-primary` with `text-primary-foreground` — navy with cream text in light, gold with espresso text in dark. `text-sm font-medium`, icons at `16px` with an `8px` gap.
- **Hover / Focus:** primary drops to `90%` opacity on hover; focus-visible shifts the border to `--ring` and adds a `3px` ring at `50%` opacity. Transitions run on `transition-all`.
- **Outline:** hairline border on the page background, hover fills with the pale gold accent. In dark mode it uses a translucent input fill (`bg-input/30`) instead.
- **Ghost:** no fill at rest, accent fill on hover. The default for icon-only controls in headers and rows.
- **Destructive:** crimson fill with white text and a crimson focus ring. Dark mode drops it to `60%` opacity so it doesn't glare on espresso.
- **Link:** primary-coloured text with a `4px` underline offset, underlined on hover only.

### Severity Chips

The product's signature control — the six-level filter row in the log toolbar.

- **Style:** fully round, `4px 10px`, `12px` capitalised text, each carrying its severity dot at `8px`.
- **Active:** greige `bg-secondary` fill, `font-semibold`, a `foreground/25` border, and the dot at full opacity.
- **Inactive:** page-background fill, `font-medium` muted ink, hairline border, and the dot faded to `40%`.
- **Behaviour:** `aria-pressed` carries the state; the dot's opacity plus the weight change means the toggle never depends on colour alone. Focus-visible gets the standard `3px` ring.

### Cards / Containers

- **Corner Style:** `12px` (`rounded-xl`).
- **Background:** `bg-card` — warm near-white in light, `hsl(28 20% 12%)` in dark. Required, per the Surface Declaration Rule.
- **Shadow Strategy:** flat at rest (see Elevation & Depth; the incumbent `shadow-sm` is known drift).
- **Border:** a single hairline.
- **Internal Padding:** `24px` vertical with `24px` horizontal on header and content; the log toolbar runs tighter at `12px` because it is a control surface, not a reading surface.

### Inputs / Fields

- **Style:** `36px` tall, `6px` radius, `4px 12px` padding, `bg-transparent` with a `border-input` stroke. In light mode the field inherits the card's near-white and depends entirely on that darker stroke for definition — which is why field-edge is tuned darker than hairline.
- **Focus:** border shifts to `--ring`, plus a `3px` ring at `50%`. Transition is limited to `color, box-shadow` so the field doesn't reflow.
- **Error:** `aria-invalid` turns the border and ring destructive. Messages render below in `12px` destructive text.
- **Disabled:** `50%` opacity, `cursor-not-allowed`, pointer events off.
- **Labels:** always present, `12px` muted ink, `6px` above the field.
- **Search:** the search input takes a `16px` icon absolutely positioned at `10px` from the left with `pl-8` on the field. Debounced, never a submit button.

### Navigation

- **Sidebar:** a rail one tone below the page, holding the Bilis logo tile (an `8px`-radius `bg-sidebar-primary` square with the three-stripe mark at `20px`), the main nav, and the user menu at the foot. Active items take the sidebar accent fill.
- **Breadcrumbs:** `14px`, muted ink for ancestors, foreground for the current page. One page header per page — the breadcrumb bar is the page's identity, not a second `<h1>`.
- **Hover / Active:** accent fill, never an underline, never a colour shift on the label alone.

### Log Entry Row

The signature component. Everything else exists to frame it.

- **Structure:** a full-width `button` row (`6px 12px`, monospace, `12px`) followed by an expandable detail panel. Columns in fixed order: chevron (`14px`, muted), timestamp (`tabular-nums`, muted), severity (`64px`, uppercase, semibold, dot + label in the severity colour), service name (`160px`, truncated, muted), message (flexes, truncated until expanded).
- **Separator:** a bottom hairline at `sidebar-border/70`. No card, no radius, no shadow — rows are ruled lines, not stacked objects.
- **Hover:** `accent/50` fill across the whole row.
- **Expanded:** a `muted/40` panel indented to `40px` holding the wrapped body, then trace/span/scope in a two-column definition grid, then log and resource attributes. Group titles drop to `11px` Instrument Sans; keys and values stay monospace. Empty groups render an em dash, never a hidden section.

### Charts

- **Rule:** Apache ECharts only, always through `ChartCanvas.vue`, always themed from CSS custom properties via `useChartTokens()`. Chart colour is never hardcoded and never imported from a chart library's default palette.
- **Series colours:** `--chart-1..5` — gold, teal, navy, crimson, espresso in light; their lifted dark counterparts in dark, where `chart-5` becomes blush.
- **Severity charts** read the `--severity-*` variables so a bar chart and the log rows beneath it agree on what "error" looks like.

## Do's and Don'ts

### Do:

- **Do** give every panel that should read as a surface `bg-card`. A bordered box without it sits at page level and vanishes.
- **Do** reference semantic tokens (`bg-primary`, `text-muted-foreground`, `border-border`) in components. Raw brand utilities (`bg-navy`, `text-gold`) are for deliberate brand moments only.
- **Do** author every colour twice — a hand-tuned light value and a hand-tuned dark value.
- **Do** pair severity colour with the dot *and* the uppercase text label. Colour never carries severity by itself.
- **Do** use `tabular-nums` on any column of digits a reader scans vertically.
- **Do** keep monospace for machine-authored text and Instrument Sans for everything the interface says about it.
- **Do** add every new reusable component to `/styleguide` in the same change, with realistic Bilis-flavoured demo content.
- **Do** keep borders at `1px` and create emphasis with fill or tone.

### Don't:

- **Don't** use `#fff`, `#000`, or a zero-chroma grey. Every neutral in this system is warm.
- **Don't** borrow a severity colour for anything that isn't log severity.
- **Don't** assume the primary is dark — in dark mode it is gold, and it needs espresso text on top of it.
- **Don't** derive a dark-mode value by fading or lightening its light-mode counterpart.
- **Don't** put a resting shadow on a surface. Shadow is a response to hover, focus, or floating.
- **Don't** let column widths vary between log rows, or the vertical scan breaks.
- **Don't** introduce a third interface type size. 14px body and 12px support carry the product.
- **Don't** hardcode a chart colour or `import from 'echarts'` — go through `lib/echarts.ts` and `useChartTokens()`.
- **Don't** add a second page header. The breadcrumb bar is the page's identity.
