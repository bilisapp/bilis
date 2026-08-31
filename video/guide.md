# Making a Bilis video

Read this before touching `video/`. It is the accumulated result of getting
these three videos wrong first, and following it is what makes the next one
look like the last one.

`README.md` is the short orientation. This is the working reference.

---

## 1. The one rule about audience

**These are customer-facing. They are not engineering write-ups.**

This project's blog voice is candid about its own bugs, and that is right for
the blog. It is wrong for YouTube. The first cut of the OTel video had a scene
titled *"Every doc says %20. PHP ignores it"* built around a red `Unauthorized`
error, and another about an export-recursion loop. Both had to go, for two
different reasons — and the second reason is the one that will catch you:

- **Tone.** A scene whose visual payload is our own error terminal sells
  nothing.
- **Relevance, which is worse.** The recursion loop and the worker-pool
  exhaustion only happen when the traced app *is* the tracing backend. That is
  Bilis instrumenting itself. A customer sending from their Laravel app to a
  Bilis instance will never hit either. Those scenes were not just negative,
  they were *about a situation the viewer is not in* — and they made a simple
  setup look hazardous.

Before writing any scene, ask: **is this true for the viewer, or only true for
us?** Dogfooding findings are blog material. Only the customer's path goes in
the video.

Positive framing of the same fact is nearly always available and nearly always
better:

| Don't | Do |
| --- | --- |
| "The package ships metrics on. Bilis has none." | "Traces over OTLP, logs over Monolog." |
| "Every doc says %20. PHP ignores it." | "x-bilis-key needs no url-encoding in any SDK." |
| "A log line that knows its span" | "Jump from a log line to its trace" |

Keep genuinely useful warnings — just lead with the recommendation and let the
pitfall be the subordinate clause.

---

## 2. Brand rules, inherited not invented

These come from `DESIGN.md` in the app repo. If this guide and that file ever
disagree, **the app wins** — the video exists to look like the product.

- **Dark is the designed-for mode.** `theme.ts` holds the dark tokens,
  authored, never lightened from the light ones. There is no light variant.
- **The chrome is achromatic.** One neutral ladder at hue 225 — surfaces,
  borders, type, panel frames, rules. There is **no accent colour**, so
  emphasis is weight and contrast (`<Strong>`), never hue.
- **Colour belongs to data**, and only three families have it:
  - `severity` — log levels, and the red on a failed span.
  - `chart` — series colour, which is what colours a **waterfall bar, keyed by
    service**. A failed span overrides to `severity.error`, because "this
    broke" outranks "this belongs to payments".
  - `magnitude` — how big a number is. Never on a waterfall bar: the bar's
    length already encodes duration, and two encodings of one quantity fight.
- `mark.gold/teal/crimson/navy/cream` is the mark's own tail. It is the
  palette's origin. Use it for the mark, for a step chip, for a highlighted
  word — never for a surface or a border.
- **Geist** for interface text, **Geist Mono** for code and log data. Loaded via
  `@remotion/google-fonts`, which blocks the render until the face is ready.
- **No side-tab accents.** A thick coloured rule down one edge of a card is
  the most recognisable tell of generated UI, and it breaks the achromatic
  rule besides. If a panel needs a tone, put it on the label.
- **One easing curve** (`EASE`) and **one transition** (a 12-frame crossfade).
  A wipe or a flip between two code panels makes the viewer track motion at
  exactly the moment they should be reading a filename.

---

## 3. Formats and safe areas

`format.ts` defines `LANDSCAPE` and `PORTRAIT`. Components read them through
`useFormat()`, so a scene is written once and sized by whichever format wraps
it.

|  | Landscape | Portrait |
| --- | --- | --- |
| Frame | 1920×1080 | 1080×1920 |
| Margin | 120 | 56 |
| Safe top | 200 | 190 |
| Safe bottom | 120 | **420** |
| **Usable height** | **760px** | **1310px** |
| Title / Body / Code | 76 / 36 / 32 | 72 / 38 / 27 |

**The 420px bottom reserve is not whitespace.** It is where YouTube draws the
title, channel name and description over a Short. Anything placed there is not
"tight", it is *invisible*.

Portrait type is larger relative to the frame because the frame reaches the
viewer at roughly a fifth of its physical width.

**Wrap every portrait composition in `<FormatProvider format={PORTRAIT}>`,
including single-scene registrations.** `OtelShort.tsx` exports `inPortrait()`
helpers for exactly this. A scene registered without it renders at 1080×1920
with landscape type and looks like a layout bug rather than a missing provider.

---

## 4. Fitting a scene — the part that will cost you time

Every block component sets `flex-shrink: 0` **on purpose**. Before that, an
over-full scene silently compressed a terminal into a 20px sliver and still
looked plausible in a thumbnail. Now it visibly overflows the frame. Do not
"fix" an overflow by removing `flex-shrink` — fix it by cutting copy.

### Planning arithmetic

Approximate heights, good enough to plan with:

| Element | Height |
| --- | --- |
| Title line | `titleSize × 1.12` (≈ 85px landscape) |
| Body line | `bodySize × 1.5` (≈ 54px landscape) |
| Code / terminal line | `fontSize × 1.62` |
| CodeBlock chrome (header + padding) | ≈ 126px |
| Terminal chrome | ≈ 122px |
| Callout | ≈ 64 padding + 40 label + body lines |
| Waterfall | ≈ 113 (legend + padding) + `rows × rowHeight` |
| Plus each `Column` gap | as set, default 44 |

Sum it, compare to 760 (or 1310), then write to fit. Landscape reality check:
**a two-line title plus a three-line body plus two code blocks does not fit.**
That combination is what broke four scenes in a row.

### The check loop

Render a half-scale still and *look at it*. This is faster than reasoning and
it is the only way to catch a wrap you did not predict:

```bash
npx remotion still Scene-Signals   --frame=230 --scale=0.5  --output=out/x.png
npx remotion still Short-FourLines --frame=150 --scale=0.34 --output=out/x.png
```

Pick a frame late enough that every staggered element has arrived — usually
`delay of the last element + 60`.

### Copy levers, in the order to reach for them

1. Cut the title to one line. Biggest single win (≈85px).
2. Cut the body to one or two lines.
3. Reduce `Column gap` from 44 → 32 → 28.
4. Drop a code line, or lower that block's `fontSize`.
5. Only then split the scene in two.

Do not go below **27px** for code. On a phone that is already ~9px.

---

## 5. Components

| Component | Notes |
| --- | --- |
| `Stage` | Scene ground: background, grid, wash, `chapter` / `step` label |
| `Column` | The measure. **Top-aligned by default** — centring makes tall content overflow *upward into the chapter label*, and makes every scene start at a different height so the eye resets on every cut |
| `Display` `Title` `Body` `Strong` | The type scale. `Strong` is weight + full-contrast foreground, never colour |
| `CodeBlock` | Line-by-line reveal. Deliberately thin highlighter: comments muted, values teal, keywords gold, everything else plain. Indented continuation lines in `env` are coloured as values |
| `Terminal` | `prompt` / `cont` / `out` / `ok` / `error`. Use `cont` for a wrapped command so it does not get a second `❯` |
| `Waterfall` | `labelWidth`, `rowHeight`, `showDurations` — narrow the gutter and drop durations on portrait |
| `Callout` | `warn` / `error` / `note`. **The label alone carries the tone** — plain neutral card, no tinted surface and no thick coloured side rule. The app's doc callouts are `border-left: 1px solid var(--border)` with the word "Note:" doing the work; match them |
| `Grid` | Capability cards. The "what you get" scene — the strongest scene in the video |
| `BilisMark` | The real mark. `still` for a static frame; otherwise the three tail stripes sweep in behind the body |
| `Music` | See §7 |

`markPaths.ts` is **generated** from
`resources/views/components/marketing/logo-mark.blade.php`. Regenerate it if the
mark changes; never hand-edit it.

### The punch quarantine

`brand/punch.tsx` (`PunchIn`, `WordPop`, `Ticker`, `StepChip`) breaks every
restraint rule above on purpose, for the high-energy Short. **It is used by
exactly one composition. Keep it that way.** If a second composition needs it,
that is a conversation, not a refactor.

---

## 6. Render settings

`remotion.config.ts` sets **PNG frames** and **`yuv420p`**. Both matter and both
were bought with a wrong render:

JPEG frames make the encoder tag the stream **full-range (`yuvj420p`)**, and a
player that ignores the range flag lifts the blacks. On a video that is almost
entirely one dark surface, that reads as a washed-out background. Passing
`--pixel-format=yuv420p` alone does **not** fix it — the frame format forces it.
PNG also keeps small text and 1px borders crisp instead of ringing them.

Always verify after rendering:

```bash
ffprobe -v error -show_entries stream=width,height,pix_fmt:format=duration \
  -of default=noprint_wrappers=1 out/name.mp4
```

---

## 7. Music

`src/soundtrack.ts` maps each composition id to a track or to `null`.

**An agent cannot fetch tracks.** The YouTube Audio Library is inside YouTube
Studio behind the user's Google account; the URL redirects to
`accounts.google.com`. Ask the human to download an `.mp3` and say so plainly —
do not substitute audio from elsewhere, because the licence is the whole point.

When a file arrives:

1. **Put it in `public/`.** `staticFile()` resolves nowhere else. Rename to a
   space-free lowercase filename.
2. **Measure it, do not guess a start point.** Decode to mono PCM and compute
   per-second RMS to find where the arrangement actually lands:

   ```bash
   ffmpeg -v error -i public/music/track.mp3 -ac 1 -ar 8000 -f s16le /tmp/pcm.raw
   ```

   Most library tracks spend their first bars arriving, and a 27-second Short
   cannot afford that. Set `startAtSeconds` past the build.
3. **Set the level against LUFS, not by ear.** Measure the finished render:

   ```bash
   ffmpeg -hide_banner -i out/name.mp4 -map a:0 -af ebur128 -f null - 2>&1 \
     | grep -A3 "Integrated loudness"
   ```

   **YouTube normalises to about −14 LUFS and only ever turns audio *down*.** A
   mix at −22 stays at −22 and viewers conclude the video has no sound. Targets
   that worked:

   | Cut | Integrated | Reasoning |
   | --- | --- | --- |
   | High-energy Short | **−13** | Just under target, so it ships as mixed |
   | Calm Short | **−17** | Present, not pushy |
   | Long explainer | **−19** | Felt, not listened to — people are reading code |

4. **Attribution.** If the library says a track requires credit, put the exact
   line in `soundtrack.ts` *and* in the description. An agent cannot verify the
   licence — flag it for the human every time.

The `Music` component trims the track to the composition's own length and fades
both ends, so the source file can be any duration.

---

## 8. Channel banner

`src/channel/Banner.tsx`, a `Still` at 2560×1440. YouTube crops one upload three
ways, so **the layout is driven by the crops, not the canvas**:

| Surface | Crop |
| --- | --- |
| TV | 2560×1440 — everything |
| Desktop | 2560×423 centre strip |
| Phone | **1546×423 — the only region guaranteed everywhere** |

Mark, wordmark and tagline all live inside the phone-safe rectangle. Anything
outside it is decoration, never information.

**The tagline is a positioning statement, so get it right before rendering.** It
reads "OpenTelemetry logs and traces, self-hosted" and each half is deliberate:
Bilis accepts OTLP from anything that speaks it, so it is **not** "for Laravel"
— the channel's Laravel content is a use case, not the scope. And it says logs
and traces, not "observability" or "logs, traces and metrics": metrics are out
of scope, and a banner is the last place to imply a capability. The same caution
applies to any scene copy that describes what Bilis *is* rather than what the
viewer is doing. Render `YouTubeBanner-Guides` to
see both boundaries drawn, and verify by cropping the final PNG with ffmpeg.

### Generated artwork

`public/banner-art.png` came from OpenAI `gpt-image-2`. Two things to carry
forward:

- The model gives you **composition, not palette.** The first attempt was a
  saturated cyan-blue with a good layout. Re-prompt with explicit negatives
  ("almost monochrome, never neon, never electric blue, only three or four
  restrained accents") and then finish the grade in the component
  (`saturate(0.85) brightness(0.92)` plus a radial darkening).
- **Never ask it for text or the logo.** Image models garble type and cannot
  reproduce a specific mark. Generate the background; composite the real mark
  and real Geist type on top.

---

## 9. Workflow

```bash
cd video
npm install
npx remotion studio --no-open          # preview, re-time scenes
npx tsc --noEmit                       # must pass before you render
npx remotion compositions              # list ids and durations

npx remotion render OtelSetup       --codec=h264 --crf=18 --output=out/long.mp4
npx remotion render OtelShort       --codec=h264 --crf=18 --output=out/short.mp4
npx remotion render OtelShortPunchy --codec=h264 --crf=18 --output=out/punchy.mp4
npx remotion still  YouTubeBanner   --output=out/banner.png
```

### Adding a video

1. `src/<name>/scenes/*.tsx`, then a `<Name>.tsx` assembling them with
   `TransitionSeries`.
2. Register **the video and every scene** in `src/Root.tsx`. Registering scenes
   individually is what lets you double-click a sequence in the timeline to jump
   to it, and re-time one scene without scrubbing the whole thing.
3. Keep `durationInFrames` **inline** in the JSX. Only inline values stay
   draggable in Studio; a value pulled from a constant is not editable there.
4. Add a `SOUNDTRACK` entry — `null` is fine, but the key should exist.

### Things that will bite

- Editor and formatter **reorder imports**. Anchored string edits against import
  blocks fail silently-ish; re-read the file rather than assuming your patch
  landed.
- `staticFile()` only resolves from `public/`.
- Scene copy must match the real repo. Every command, env var and code snippet
  in these videos was verified against `config/`, `.env.example` and
  `resources/docs/` — if you change a snippet, check it still reflects the app.
- Hard cuts (no `TransitionSeries.Transition`) are correct for the punchy Short
  and wrong everywhere else.
- The user edits these files too. If something looks changed and deliberate,
  it is — do not revert it.

---

## 10. Current inventory

| Composition | Format | Length | Purpose |
| --- | --- | --- | --- |
| `OtelSetup` | 1920×1080 | 79s | Six-step explainer, the main upload |
| `OtelShort` | 1080×1920 | 36s | Calm Short — expected to win click-through |
| `OtelShortPunchy` | 1080×1920 | 27s | High-energy Short — expected to win views |
| `YouTubeBanner` | 2560×1440 | still | Channel art |

Both Shorts exist to be A/B tested. Do not delete one to "consolidate".
