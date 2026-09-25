import React from "react";
import {
  AbsoluteFill,
  Easing,
  Sequence,
  interpolate,
  useCurrentFrame,
} from "remotion";
import { EASE, mono, neutral, sans } from "../brand";

/**
 * The launch-video kit: the "product film" register, one step up from the
 * explainers.
 *
 * Same brand rules — achromatic chrome, colour only on data, Geist and Geist
 * Mono, one easing curve — with the depth the explainers never had: film
 * grain, a vignette, a slow camera, panels that sit in space and catch a light
 * sweep, and headlines that rise out of a mask instead of fading in. Nothing
 * here adds hue; every glow in the film comes from a data series.
 */

export const ease = Easing.bezier(...EASE);

/** Clamp-both-ends interpolate with the house curve. */
export const tween = (
  frame: number,
  input: [number, number],
  output: [number, number],
  easing: (t: number) => number = ease,
): number =>
  interpolate(frame, input, output, {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
    easing,
  });

/**
 * The ground every scene stands on: background, a barely-there dot grid that
 * drifts, a soft top light, a vignette and film grain.
 *
 * The grain is what stops a large flat dark surface from banding and looking
 * like a screenshot; it re-seeds every other frame so it reads as film, not
 * as a texture.
 *
 * The scene's own content waits `enterAt` frames — longer than the 12-frame
 * crossfade's midpoint — so a transition dissolves the old scene onto this
 * one's empty ground, and the new headline rises on a clean stage instead of
 * through the old one.
 */
export const Atmosphere: React.FC<{ children: React.ReactNode; enterAt?: number }> = ({
  children,
  enterAt = 10,
}) => {
  const frame = useCurrentFrame();

  return (
    <AbsoluteFill style={{ backgroundColor: neutral.background, overflow: "hidden" }}>
      <AbsoluteFill
        style={{
          backgroundImage: `radial-gradient(${neutral.border} 1.4px, transparent 1.6px)`,
          backgroundSize: "44px 44px",
          opacity: 0.45,
          translate: `${interpolate(frame, [0, 300], [0, -44])}px 0px`,
          maskImage:
            "radial-gradient(ellipse 70% 60% at 50% 45%, black 20%, transparent 80%)",
        }}
      />
      <AbsoluteFill
        style={{
          background:
            "radial-gradient(ellipse 60% 45% at 50% -8%, hsl(225 20% 22% / 0.55), transparent 70%)",
        }}
      />
      <Sequence from={enterAt} layout="none" name="Content">
        {children}
      </Sequence>
      <AbsoluteFill
        style={{
          background:
            "radial-gradient(ellipse 85% 80% at 50% 50%, transparent 55%, hsl(225 20% 3% / 0.75) 100%)",
          pointerEvents: "none",
        }}
      />
      <Grain seed={Math.floor(frame / 2)} />
    </AbsoluteFill>
  );
};

const Grain: React.FC<{ seed: number }> = ({ seed }) => (
  <AbsoluteFill style={{ opacity: 0.07, mixBlendMode: "overlay", pointerEvents: "none" }}>
    <svg width="100%" height="100%">
      <filter id={`grain-${seed}`}>
        <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="2" seed={seed} stitchTiles="stitch" />
        <feColorMatrix type="saturate" values="0" />
      </filter>
      <rect width="100%" height="100%" filter={`url(#grain-${seed})`} />
    </svg>
  </AbsoluteFill>
);

/**
 * A camera that places panels in space: a 3D tilt that settles and then holds.
 *
 * It deliberately never keeps moving once settled. A continuous push-in
 * re-rasterises every glyph at a new scale on every frame, and Chrome snaps
 * text to the pixel grid, so headlines and axis numbers visibly shimmer a
 * pixel or two frame to frame. Motion belongs to entrances; a resting frame
 * must be a still one.
 */
export const Camera: React.FC<{
  children: React.ReactNode;
  tilt?: { x: [number, number]; y: [number, number] };
}> = ({ children, tilt }) => {
  const frame = useCurrentFrame();
  const rotateX = tilt ? tween(frame, [0, 50], tilt.x) : 0;
  const rotateY = tilt ? tween(frame, [0, 50], tilt.y) : 0;

  return (
    <AbsoluteFill style={{ perspective: 2200, perspectiveOrigin: "50% 40%" }}>
      <AbsoluteFill
        style={{
          transform: tilt ? `rotateX(${rotateX}deg) rotateY(${rotateY}deg)` : undefined,
          transformStyle: "preserve-3d",
        }}
      >
        {children}
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

/**
 * A headline whose lines rise out of a mask, one after another.
 *
 * Hierarchy by contrast, never hue: a line can be `dim` (the setup) or full
 * (the payoff), and at most one word may carry a data colour.
 */
export const Headline: React.FC<{
  lines: { text: string; dim?: boolean; accent?: { word: string; color: string } }[];
  size?: number;
  delay?: number;
  stagger?: number;
  align?: "left" | "center";
}> = ({ lines, size = 96, delay = 0, stagger = 7, align = "left" }) => {
  const frame = useCurrentFrame();

  return (
    <div style={{ display: "flex", flexDirection: "column", alignItems: align === "center" ? "center" : "flex-start" }}>
      {lines.map((line, index) => {
        const at = delay + index * stagger;
        const words = line.text.split(" ");

        return (
          <div key={line.text} style={{ overflow: "hidden", paddingBottom: size * 0.12, marginBottom: -size * 0.12 }}>
            <div
              style={{
                fontFamily: sans,
                fontSize: size,
                fontWeight: 650,
                letterSpacing: -size * 0.035,
                lineHeight: 1.04,
                color: line.dim ? neutral.mutedForeground : neutral.foreground,
                translate: `0px ${tween(frame, [at, at + 20], [110, 0])}%`,
                opacity: tween(frame, [at, at + 8], [0, 1]),
                textAlign: align,
                whiteSpace: "nowrap",
              }}
            >
              {words.map((word, wordIndex) => (
                <span
                  key={wordIndex}
                  style={{
                    color:
                      line.accent && word.replace(/[^\w]/g, "") === line.accent.word
                        ? line.accent.color
                        : undefined,
                  }}
                >
                  {word}
                  {wordIndex < words.length - 1 ? " " : ""}
                </span>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
};

/** A quiet line under a headline. */
export const Sub: React.FC<{ children: React.ReactNode; delay?: number; size?: number }> = ({
  children,
  delay = 0,
  size = 34,
}) => {
  const frame = useCurrentFrame();

  return (
    <div
      style={{
        fontFamily: sans,
        fontSize: size,
        lineHeight: 1.4,
        color: neutral.mutedForeground,
        opacity: tween(frame, [delay, delay + 14], [0, 1]),
        translate: `0px ${tween(frame, [delay, delay + 18], [14, 0])}px`,
      }}
    >
      {children}
    </div>
  );
};

/** The small uppercase label above a headline. */
export const Eyebrow: React.FC<{ children: React.ReactNode; delay?: number }> = ({
  children,
  delay = 0,
}) => {
  const frame = useCurrentFrame();

  return (
    <div
      style={{
        fontFamily: mono,
        fontSize: 22,
        letterSpacing: 5,
        textTransform: "uppercase",
        color: neutral.mutedForeground,
        opacity: tween(frame, [delay, delay + 12], [0, 1]),
      }}
    >
      {children}
    </div>
  );
};

/**
 * An app surface floating in space: the card colour, a hairline border, a top
 * highlight, a deep soft shadow, and one light sweep that crosses it after it
 * lands.
 */
export const Panel: React.FC<{
  children: React.ReactNode;
  width: number;
  height?: number;
  delay?: number;
  sweepAt?: number;
  padding?: number;
}> = ({ children, width, height, delay = 0, sweepAt, padding = 32 }) => {
  const frame = useCurrentFrame();
  const sweep = sweepAt ?? delay + 26;

  return (
    <div
      style={{
        position: "relative",
        width,
        height,
        padding,
        borderRadius: 22,
        backgroundColor: neutral.card,
        border: `1px solid ${neutral.border}`,
        boxShadow:
          "0 1px 0 hsl(225 30% 100% / 0.05) inset, 0 50px 120px -20px hsl(225 30% 2% / 0.8), 0 18px 40px -18px hsl(225 30% 2% / 0.7)",
        overflow: "hidden",
        opacity: tween(frame, [delay, delay + 14], [0, 1]),
        translate: `0px ${tween(frame, [delay, delay + 26], [40, 0])}px`,
        scale: tween(frame, [delay, delay + 26], [0.97, 1]),
      }}
    >
      {children}
      <div
        style={{
          position: "absolute",
          inset: 0,
          background:
            "linear-gradient(105deg, transparent 35%, hsl(225 30% 100% / 0.07) 50%, transparent 65%)",
          translate: `${tween(frame, [sweep, sweep + 40], [-110, 110])}% 0px`,
          pointerEvents: "none",
        }}
      />
    </div>
  );
};

/** A toolbar chip as the explorer draws it: mono, muted surface. */
export const Chip: React.FC<{
  children: React.ReactNode;
  label?: string;
  dot?: string;
  delay?: number;
  size?: number;
}> = ({ children, label, dot, delay = 0, size = 24 }) => {
  const frame = useCurrentFrame();

  return (
    <div
      style={{
        display: "inline-flex",
        alignItems: "center",
        gap: 12,
        padding: `${size * 0.42}px ${size * 0.7}px`,
        borderRadius: 999,
        backgroundColor: neutral.muted,
        border: `1px solid ${neutral.border}`,
        fontFamily: mono,
        fontSize: size,
        color: neutral.foreground,
        whiteSpace: "nowrap",
        opacity: tween(frame, [delay, delay + 10], [0, 1]),
        translate: `0px ${tween(frame, [delay, delay + 16], [10, 0])}px`,
      }}
    >
      {dot ? (
        <span style={{ width: size * 0.42, height: size * 0.42, borderRadius: 999, backgroundColor: dot, boxShadow: `0 0 ${size * 0.6}px ${dot}` }} />
      ) : null}
      {label ? <span style={{ color: neutral.mutedForeground }}>{label}</span> : null}
      <span>{children}</span>
    </div>
  );
};

/** A number that counts up, in tabular mono. */
export const Counter: React.FC<{
  to: number;
  from?: number;
  delay?: number;
  frames?: number;
  format?: (value: number) => string;
  size?: number;
  color?: string;
}> = ({ to, from = 0, delay = 0, frames = 30, format = (v) => Math.round(v).toLocaleString("en-US"), size = 64, color = neutral.foreground }) => {
  const frame = useCurrentFrame();

  return (
    <span
      style={{
        fontFamily: mono,
        fontSize: size,
        fontWeight: 500,
        fontVariantNumeric: "tabular-nums",
        letterSpacing: -size * 0.02,
        color,
      }}
    >
      {format(tween(frame, [delay, delay + frames], [from, to]))}
    </span>
  );
};
