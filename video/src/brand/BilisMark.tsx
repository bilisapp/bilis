import React from "react";
import { Easing, Interactive, interpolate, useCurrentFrame } from "remotion";
import { MARK_PATHS, MARK_VIEWBOX } from "./markPaths";
import { EASE, mark, neutral } from "./theme";

/**
 * The Bilis mark, from the application's own SVG.
 *
 * The three tail stripes are separate paths in the source specifically so CSS
 * can reach them, which is what makes this animatable: the body draws first,
 * then gold, teal and crimson sweep in behind it, staggered. That is the only
 * motion the mark ever does — the tail is the brand, so it is the thing that
 * moves, and it moves in palette order.
 */
export const BilisMark: React.FC<{
  /** Rendered width in px. The mark keeps its own aspect ratio. */
  width?: number;
  /** Frame at which the body appears. Stripes follow it. */
  delay?: number;
  /** Skip the entrance and render the finished mark. */
  still?: boolean;
}> = ({ width = 520, delay = 0, still = false }) => {
  const frame = useCurrentFrame();

  /** The stripes fan in from the tail end, so they animate right-to-left. */
  const stripeOrder: Record<string, number> = { gold: 0, teal: 1, crimson: 2 };

  return (
    <Interactive.Div
      name="Bilis mark"
      style={{
        width,
        display: "flex",
      }}
    >
      <svg
        viewBox={MARK_VIEWBOX}
        style={{ width: "100%", height: "auto", overflow: "visible" }}
      >
        {MARK_PATHS.map((path, index) => {
          const isStripe = path.role in stripeOrder;
          const start = delay + (isStripe ? 6 + stripeOrder[path.role] * 4 : 0);

          const fill =
            path.role === "body"
              ? mark.cream
              : path.role === "detail"
                ? neutral.background
                : mark[path.role as "gold" | "teal" | "crimson"];

          return (
            <path
              key={index}
              d={path.d}
              fill={fill}
              style={{
                opacity: still
                  ? 1
                  : interpolate(frame, [start, start + 14], [0, 1], {
                      extrapolateLeft: "clamp",
                      extrapolateRight: "clamp",
                      easing: Easing.bezier(...EASE),
                    }),
                /*
                 * Stripes slide in from behind the body along the tail's own
                 * axis. The body itself does not translate — it is the anchor
                 * the eye holds while the colour arrives.
                 */
                translate: still
                  ? "0px 0px"
                  : interpolate(
                      frame,
                      [start, start + 20],
                      [isStripe ? "-260px 0px" : "0px 0px", "0px 0px"],
                      {
                        extrapolateLeft: "clamp",
                        extrapolateRight: "clamp",
                        easing: Easing.bezier(...EASE),
                      },
                    ),
              }}
            />
          );
        })}
      </svg>
    </Interactive.Div>
  );
};

/**
 * Mark plus wordmark, which is the lockup every surface uses.
 */
export const BilisLockup: React.FC<{
  width?: number;
  delay?: number;
  still?: boolean;
}> = ({ width = 460, delay = 0, still = false }) => {
  return <BilisMark width={width} delay={delay} still={still} />;
};
