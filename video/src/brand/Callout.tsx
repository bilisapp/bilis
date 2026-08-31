import React from "react";
import { Easing, Interactive, interpolate, useCurrentFrame } from "remotion";
import { sans } from "./fonts";
import { useFormat } from "./format";
import { EASE, neutral, severity } from "./theme";

/**
 * The "read this or you will lose an hour" panel.
 *
 * Three tones, and the colour is carried by a 4px rule and the label only — the
 * surface stays neutral. A fully tinted card would put a large field of hue on
 * screen and make the data in the next scene read as less important than the
 * warning about it.
 */
export const Callout: React.FC<{
  tone?: "warn" | "error" | "note";
  label: string;
  children: React.ReactNode;
  delay?: number;
  /** Defaults to the active format's content width. */
  width?: number;
}> = ({ tone = "warn", label, children, delay = 0, width }) => {
  const frame = useCurrentFrame();
  const format = useFormat();

  const accent =
    tone === "error"
      ? severity.error
      : tone === "warn"
        ? severity.warn
        : severity.info;

  return (
    <Interactive.Div
      name={`Callout · ${label}`}
      style={{
        width: width ?? format.width - format.margin * 2,
        display: "flex",
        gap: 30,
        backgroundColor: neutral.card,
        borderRadius: 16,
        border: `1px solid ${neutral.border}`,
        borderLeft: `4px solid ${accent}`,
        padding: "32px 40px",
        flexShrink: 0,
        opacity: interpolate(frame, [delay, delay + 14], [0, 1], {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
          easing: Easing.bezier(...EASE),
        }),
        translate: interpolate(
          frame,
          [delay, delay + 20],
          ["0px 20px", "0px 0px"],
          {
            extrapolateLeft: "clamp",
            extrapolateRight: "clamp",
            easing: Easing.bezier(...EASE),
          },
        ),
      }}
    >
      <div style={{ flex: 1, fontFamily: sans }}>
        <div
          style={{
            fontSize: format.type.label,
            fontWeight: 600,
            letterSpacing: 1.4,
            textTransform: "uppercase",
            color: accent,
            marginBottom: 12,
          }}
        >
          {label}
        </div>
        <div
          style={{
            fontSize: format.type.body,
            lineHeight: 1.45,
            color: neutral.foreground,
          }}
        >
          {children}
        </div>
      </div>
    </Interactive.Div>
  );
};
