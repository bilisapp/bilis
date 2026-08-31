/**
 * Geist for the interface, Geist Mono for log and code data — the same pairing
 * the application self-hosts through the Vite font plugin.
 *
 * `loadFont()` blocks rendering until the face is ready, which is what stops a
 * render from capturing a frame in the fallback font.
 */
import { loadFont as loadGeist } from "@remotion/google-fonts/Geist";
import { loadFont as loadGeistMono } from "@remotion/google-fonts/GeistMono";

export const { fontFamily: sans } = loadGeist("normal", {
  weights: ["400", "500", "600", "700"],
  subsets: ["latin"],
});

export const { fontFamily: mono } = loadGeistMono("normal", {
  weights: ["400", "500", "600"],
  subsets: ["latin"],
});
