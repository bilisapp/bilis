import React from "react";
import { Easing, Interactive, interpolate, useCurrentFrame } from "remotion";
import { mono, sans } from "./fonts";
import { useFormat } from "./format";
import { EASE, neutral, severity } from "./theme";

export type GridItem = {
  label: string;
  detail: string;
};

/**
 * A grid of capabilities, staggered in.
 *
 * For the scene that answers "what do I get for this" — the one place a
 * tutorial is allowed to simply list things. Cards stay neutral: a set of
 * equally-weighted items should not have one of them tinted, and there is no
 * accent colour to tint it with.
 */
export const Grid: React.FC<{
  items: GridItem[];
  columns?: number;
  delay?: number;
  stagger?: number;
}> = ({ items, columns = 4, delay = 0, stagger = 4 }) => {
  const frame = useCurrentFrame();
  const format = useFormat();

  return (
    <Interactive.Div
      name="Grid"
      style={{
        display: "grid",
        gridTemplateColumns: `repeat(${columns}, 1fr)`,
        gap: 20,
        flexShrink: 0,
      }}
    >
      {items.map((item, index) => {
        const itemDelay = delay + index * stagger;

        return (
          <div
            key={item.label}
            style={{
              backgroundColor: neutral.card,
              border: `1px solid ${neutral.border}`,
              borderRadius: 14,
              padding: "24px 26px",
              opacity: interpolate(frame, [itemDelay, itemDelay + 12], [0, 1], {
                extrapolateLeft: "clamp",
                extrapolateRight: "clamp",
                easing: Easing.bezier(...EASE),
              }),
              translate: interpolate(
                frame,
                [itemDelay, itemDelay + 18],
                ["0px 18px", "0px 0px"],
                {
                  extrapolateLeft: "clamp",
                  extrapolateRight: "clamp",
                  easing: Easing.bezier(...EASE),
                },
              ),
            }}
          >
            <div
              style={{
                display: "flex",
                alignItems: "center",
                gap: 12,
                fontFamily: sans,
                fontSize: format.type.label + 3,
                fontWeight: 600,
                color: neutral.foreground,
                marginBottom: 8,
              }}
            >
              <span style={{ color: severity.debug, fontSize: 26 }}>✓</span>
              {item.label}
            </div>
            <div
              style={{
                fontFamily: mono,
                fontSize: format.type.label - 3,
                color: neutral.mutedForeground,
              }}
            >
              {item.detail}
            </div>
          </div>
        );
      })}
    </Interactive.Div>
  );
};
