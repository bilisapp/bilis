/**
 * The Bilis palette, lifted verbatim from `resources/css/app.css`.
 *
 * The rule the interface follows applies here too: **colour belongs to data,
 * the chrome is achromatic.** Everything structural — surfaces, borders, type,
 * rules, the frame around a code block — comes out of `neutral`, which is one
 * ladder at hue 225. Only three families carry hue, and all three are data:
 * severity, chart series (which is also what colours a waterfall bar), and
 * magnitude.
 *
 * Dark is the designed-for mode, so the video is dark. These are the dark
 * values, not lightened light ones.
 */

/** The one neutral ladder. Hue 225, per-mode saturation. */
export const neutral = {
  background: "hsl(225 14% 8%)",
  sidebar: "hsl(225 15% 6%)",
  card: "hsl(225 13% 11%)",
  popover: "hsl(225 13% 13%)",
  code: "hsl(225 13% 12%)",
  muted: "hsl(225 11% 15%)",
  accent: "hsl(225 11% 18%)",
  border: "hsl(225 10% 20%)",
  input: "hsl(225 10% 27%)",
  ring: "hsl(225 10% 58%)",
  mutedForeground: "hsl(225 10% 62%)",
  foreground: "hsl(225 16% 93%)",
  codeForeground: "hsl(225 16% 90%)",
} as const;

/**
 * The mark's tail. This is the palette's origin — every hue below descends
 * from it — and it is never used for chrome.
 */
export const mark = {
  gold: "#f3c440",
  teal: "#45bfa6",
  crimson: "#d8394a",
  navy: "#1f3a5f",
  cream: "#f3f0e7",
} as const;

/** Severity. Data, and the only thing that may colour a log level. */
export const severity = {
  trace: "hsl(225 8% 54%)",
  debug: "hsl(167 48% 52%)",
  info: "hsl(214 66% 66%)",
  warn: "hsl(45 85% 61%)",
  error: "hsl(354 74% 66%)",
  fatal: "hsl(330 72% 72%)",
} as const;

/**
 * Chart series — and therefore span waterfall bars, keyed by service. Five
 * slots, cycled. A failed span overrides to `severity.error`, because "this
 * broke" outranks "this belongs to payments".
 */
export const chart = [
  "hsl(45 85% 61%)",
  "hsl(167 50% 55%)",
  "hsl(214 62% 66%)",
  "hsl(354 72% 66%)",
  "hsl(330 62% 72%)",
] as const;

/** The video's own frame. 1080p, 30fps, generous margins for YouTube. */
export const video = {
  width: 1920,
  height: 1080,
  fps: 30,
  /** Nothing important goes outside this. */
  margin: 120,
} as const;

/**
 * One type scale, in px at 1920×1080.
 *
 * Sized for video, not for a page: the floor is 28px because YouTube's mobile
 * player is roughly a fifth of this width, and anything smaller is gone.
 */
export const type = {
  display: 104,
  title: 76,
  heading: 52,
  body: 36,
  code: 32,
  label: 28,
} as const;

/** Shared easing. One curve for everything that enters, so the video has a gait. */
export const EASE = [0.16, 1, 0.3, 1] as const;
