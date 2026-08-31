import React, { createContext, useContext } from "react";

/**
 * A frame's shape, its safe area and its type scale.
 *
 * Landscape and portrait are not the same video cropped. A Short is watched on
 * a phone held in one hand, with YouTube's own chrome sitting on top of the
 * frame — so it needs its own margins and its own type sizes, and the
 * components read both from here rather than hard-coding 1920×1080.
 */
export type Format = {
  width: number;
  height: number;
  fps: number;

  /** Horizontal margin. Nothing but a full-bleed background crosses it. */
  margin: number;
  /** Space above the content, which is where the chapter label sits. */
  safeTop: number;
  /**
   * Space below the content.
   *
   * On a Short this is not whitespace — it is the strip YouTube covers with
   * the title, channel name and description. Anything put there is not
   * "tight", it is invisible.
   */
  safeBottom: number;

  type: {
    display: number;
    title: number;
    heading: number;
    body: number;
    code: number;
    label: number;
  };
};

export const LANDSCAPE: Format = {
  width: 1920,
  height: 1080,
  fps: 30,
  margin: 120,
  safeTop: 200,
  safeBottom: 120,
  type: {
    display: 104,
    title: 76,
    heading: 52,
    body: 36,
    code: 32,
    label: 28,
  },
};

/**
 * 1080×1920 for a YouTube Short.
 *
 * Type is larger *relative to the frame* than in landscape, because the frame
 * itself is roughly a fifth the physical width when it reaches the viewer. The
 * 420px bottom reserve is YouTube's overlay, measured generously — losing the
 * last line of a code block to the description text is the standard way a
 * vertical video goes wrong.
 */
export const PORTRAIT: Format = {
  width: 1080,
  height: 1920,
  fps: 30,
  margin: 56,
  safeTop: 190,
  safeBottom: 420,
  type: {
    display: 92,
    title: 72,
    heading: 48,
    body: 38,
    code: 27,
    label: 27,
  },
};

const FormatContext = createContext<Format>(LANDSCAPE);

export const useFormat = (): Format => useContext(FormatContext);

export const FormatProvider: React.FC<{
  format: Format;
  children: React.ReactNode;
}> = ({ format, children }) =>
  React.createElement(FormatContext.Provider, { value: format }, children);
