import React from "react";
import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from "remotion";
import { sans } from "./fonts";
import { useFormat } from "./format";
import { EASE, mark, neutral } from "./theme";

/**
 * The ground every scene stands on.
 *
 * Deliberately almost nothing: a flat `background`, a very low-contrast grid,
 * and one slow wash that keeps a 1080p flat fill from banding on YouTube's
 * encoder. The interface is achromatic, so the backdrop is too — the only hue
 * in a frame should be the data in it.
 */
export const Stage: React.FC<{
  children: React.ReactNode;
  /** Shown top-left for the length of the scene. */
  chapter?: string;
  /** Step number, shown beside the chapter. */
  step?: string;
}> = ({ children, chapter, step }) => {
  const frame = useCurrentFrame();
  const format = useFormat();

  return (
    <AbsoluteFill
      name="Stage"
      style={{
        backgroundColor: neutral.background,
        fontFamily: sans,
        color: neutral.foreground,
      }}
    >
      {/* The grid. Structural, so it is drawn out of the neutral ladder. */}
      <AbsoluteFill
        name="Grid"
        style={{
          backgroundImage: `linear-gradient(${neutral.card} 1px, transparent 1px), linear-gradient(90deg, ${neutral.card} 1px, transparent 1px)`,
          backgroundSize: "80px 80px",
          opacity: 0.55,
          maskImage:
            "radial-gradient(ellipse 90% 70% at 50% 45%, black 30%, transparent 100%)",
        }}
      />

      {/* One slow wash so a flat dark fill does not band under compression. */}
      <AbsoluteFill
        name="Wash"
        style={{
          background: `radial-gradient(ellipse 70% 55% at 50% 0%, ${neutral.card} 0%, transparent 65%)`,
          opacity: interpolate(frame, [0, 90], [0.5, 0.9], {
            extrapolateLeft: "clamp",
            extrapolateRight: "clamp",
          }),
        }}
      />

      {chapter ? (
        <AbsoluteFill
          name="Chapter"
          style={{
            padding: format.margin,
            justifyContent: "flex-start",
            alignItems: "flex-start",
          }}
        >
          <div
            style={{
              display: "flex",
              alignItems: "center",
              gap: 20,
              fontSize: format.type.label,
              letterSpacing: 1.6,
              textTransform: "uppercase",
              color: neutral.mutedForeground,
              opacity: interpolate(frame, [4, 20], [0, 1], {
                extrapolateLeft: "clamp",
                extrapolateRight: "clamp",
                easing: Easing.bezier(...EASE),
              }),
            }}
          >
            {step ? (
              <span
                style={{
                  color: neutral.background,
                  backgroundColor: mark.gold,
                  borderRadius: 8,
                  padding: "4px 14px",
                  fontWeight: 600,
                  letterSpacing: 0.5,
                }}
              >
                {step}
              </span>
            ) : null}
            <span>{chapter}</span>
          </div>
        </AbsoluteFill>
      ) : null}

      {children}
    </AbsoluteFill>
  );
};

/**
 * The content column. Everything sits inside this so scenes share one measure
 * and one set of margins, which is what stops a cut from feeling like a jump.
 */
export const Column: React.FC<{
  children: React.ReactNode;
  /**
   * Vertical placement within the safe area.
   *
   * Top-aligned by default, and deliberately: centring makes tall content
   * overflow *upwards* into the chapter label, and it also makes every scene
   * start at a different height, so the viewer's eye resets on every cut. A
   * tutorial wants the opposite — the title lands in the same place each time.
   */
  justify?: React.CSSProperties["justifyContent"];
  gap?: number;
}> = ({ children, justify = "flex-start", gap = 44 }) => {
  const format = useFormat();

  return (
  <AbsoluteFill
    name="Column"
    style={{
      padding: `${format.safeTop}px ${format.margin}px ${format.safeBottom}px`,
      display: "flex",
      flexDirection: "column",
      justifyContent: justify,
      gap,
    }}
  >
    {children}
  </AbsoluteFill>
  );
};
