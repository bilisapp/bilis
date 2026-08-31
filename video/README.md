# Bilis video template

Remotion project for Bilis-branded videos. Separate from the Laravel app: its
own `package.json` and `node_modules`, nothing shared, nothing added to the
application's dependencies.

```bash
cd video
npm install
npx remotion studio          # preview and re-time scenes
npx remotion render OtelSetup --codec=h264 --crf=18 --output=out/name.mp4
```

## What's in it

```
src/brand/       the template — reusable, brand-locked, not video-specific
src/otel/        the long cut, 1920×1080, 79s
src/otel-short/  the YouTube Short, 1080×1920, 36s
```

```bash
npx remotion render OtelSetup --codec=h264 --crf=18 --output=out/long.mp4
npx remotion render OtelShort --codec=h264 --crf=18 --output=out/short.mp4
```

## The template

Everything in `src/brand` encodes the application's own design rules rather
than decisions made for video. `DESIGN.md` in the app repo is the source; if the
two ever disagree, the app wins.

- **Dark is the designed-for mode.** These are the dark tokens, authored, not
  lightened from the light ones.
- **The chrome is achromatic.** One neutral ladder at hue 225 — surfaces,
  borders, type, panel frames. There is no accent colour, so emphasis is weight
  and contrast, never hue.
- **Colour belongs to data.** Three families only: severity, chart series
  (which is what colours a waterfall bar, keyed by service), and magnitude. A
  failed span overrides to the error severity, because "this broke" outranks
  "this belongs to payments".
- **Geist and Geist Mono**, loaded through `@remotion/google-fonts` so a render
  blocks until the face is ready rather than capturing a frame in the fallback.
- **One easing curve** (`EASE`) and one transition (a 12-frame crossfade), so
  everything that enters shares a gait and no cut asks the viewer to track
  motion while they are trying to read a filename.

### Components

| Component | For |
| --- | --- |
| `Stage` | Scene ground: background, grid, wash, chapter/step label |
| `Column` | The content measure. Top-aligned, so the title lands in the same place on every scene |
| `Display` / `Title` / `Body` / `Strong` | The type scale |
| `CodeBlock` | Code panel with line-by-line reveal and a deliberately thin highlighter |
| `Terminal` | Command output; errors take the error severity, success the debug teal |
| `Waterfall` | A span waterfall drawn the way the app draws one |
| `Callout` | Warn / error / note. Colour rides on a 4px rule and the label, never the surface |
| `BilisMark` | The real mark, from the app's SVG. The three tail stripes sweep in behind the body |

`markPaths.ts` is generated from
`resources/views/components/marketing/logo-mark.blade.php` — regenerate it if
the mark changes, don't hand-edit it.

## Formats and safe areas

`format.ts` holds `LANDSCAPE` and `PORTRAIT`: frame size, margins, safe area and
type scale. Components read them through `useFormat()`, so a scene is written
once and sized by whichever format wraps it. Wrap a portrait composition in
`<FormatProvider format={PORTRAIT}>` — including when registering a single
scene, or it renders at 1080×1920 with landscape type and looks like a bug.

A Short is not the wide video cropped. Portrait reserves **420px at the bottom**
because that is where YouTube draws the title, channel and description; anything
placed there is not tight, it is invisible. Type is also larger relative to the
frame, because the frame reaches the viewer at roughly a fifth of its width.

Usable content height is `height - safeTop - safeBottom` — 760px landscape,
1310px portrait. Every block component sets `flex-shrink: 0` on purpose: an
overfull scene must visibly overflow, not silently compress a terminal into a
sliver.

The fastest way to check a scene is a half-scale still:

```bash
npx remotion still Scene-Signals --frame=230 --scale=0.5 --output=out/x.png
npx remotion still Short-FourLines --frame=150 --scale=0.34 --output=out/x.png
```

## Render settings

`remotion.config.ts` sets PNG frames and `yuv420p`. Both matter: JPEG frames
make the encoder tag the stream full-range, and a player that ignores the range
flag lifts the blacks — on a video that is almost entirely one dark surface
that reads as a washed-out background.

## Adding a video

Add `src/<name>/scenes/*.tsx` and a `<Name>.tsx` that assembles them with
`TransitionSeries`, then register the video and each scene in `src/Root.tsx`.
Registering scenes individually is what lets you double-click a sequence in the
timeline to jump to it, and re-time one scene without scrubbing the whole thing.
