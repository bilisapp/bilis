---
paths:
  - 'resources/js/pages/**'
---

# Pages

## Every app page is PageHeader + bands on the work surface, never cards
The app layout makes the content pane a white surface (bg-card) and hides the breadcrumb bar on desktop; PageHeader.vue prints the layout's breadcrumbs (minus the page) as its uppercase eyebrow via usePageTrail, so keep declaring `breadcrumbs` in each page's layout option. Pages stack bands on the gutter `px-4 md:px-10`: PageHeader (title, one summary sentence, #actions) → one toolbar row (search + FilterSelect chips) → optional volume band (BarSparkline) → list (uppercase column header on bg-muted/40, hairline rows, fixed numeric lanes) → footer with KeyHint (only keys useListCursor really binds). No outer p-4, no Card wrappers around a page's own sections — h2 text-base + hairline instead. Tabs/status → SegmentedControl (hrefs for section tabs). DESIGN.md "Layout" is the spec.
