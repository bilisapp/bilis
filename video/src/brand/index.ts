/**
 * The Bilis video template.
 *
 * Everything a Bilis-branded video needs, so a new one is a folder of scenes
 * and nothing else. The rules these components encode are the application's
 * own, not decisions made for video:
 *
 * - Dark is the designed-for mode.
 * - The chrome is achromatic — one neutral ladder at hue 225.
 * - Colour belongs to data: severity, chart series (which colours a waterfall
 *   bar, keyed by service), and magnitude. There is no accent colour.
 * - Geist for the interface, Geist Mono for log and code data.
 * - One easing curve, so everything that enters shares a gait.
 *
 * See DESIGN.md in the application repo for the full system.
 */
export { BilisLockup, BilisMark } from "./BilisMark";
export { Callout } from "./Callout";
export { CodeBlock, type Lang } from "./CodeBlock";
export { FormatProvider, LANDSCAPE, PORTRAIT, useFormat, type Format } from "./format";
export { Grid, type GridItem } from "./Grid";
export { Music } from "./Music";
export { PunchIn, StepChip, Ticker, WordPop } from "./punch";
export { Column, Stage } from "./Stage";
export { Terminal, type TerminalLine } from "./Terminal";
export { Body, Display, Rise, Strong, Title } from "./Type";
export { Waterfall, type Span } from "./Waterfall";
export { mono, sans } from "./fonts";
export { EASE, chart, mark, neutral, severity, type, video } from "./theme";
