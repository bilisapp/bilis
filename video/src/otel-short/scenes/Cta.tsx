import React from "react";
import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from "remotion";
import { BilisMark, EASE, neutral, sans, Stage, useFormat } from "../../brand";

export const Cta: React.FC = () => {
  const frame = useCurrentFrame();
  const format = useFormat();

  return (
    <Stage>
      <AbsoluteFill
        name="CTA"
        style={{
          justifyContent: "center",
          alignItems: "center",
          gap: 46,
          paddingBottom: format.safeBottom - 140,
          paddingLeft: format.margin,
          paddingRight: format.margin,
          textAlign: "center",
        }}
      >
        <BilisMark width={420} delay={2} />

        <div
          style={{
            fontFamily: sans,
            fontSize: format.type.title,
            fontWeight: 600,
            letterSpacing: -1.6,
            color: neutral.foreground,
            opacity: interpolate(frame, [26, 44], [0, 1], {
              extrapolateLeft: "clamp",
              extrapolateRight: "clamp",
              easing: Easing.bezier(...EASE),
            }),
          }}
        >
          Self-hosted logs
          <br />
          and traces
        </div>

        <div
          style={{
            fontFamily: sans,
            fontSize: format.type.heading,
            color: neutral.mutedForeground,
            letterSpacing: -0.6,
            opacity: interpolate(frame, [42, 60], [0, 1], {
              extrapolateLeft: "clamp",
              extrapolateRight: "clamp",
              easing: Easing.bezier(...EASE),
            }),
          }}
        >
          bilis.app
        </div>
      </AbsoluteFill>
    </Stage>
  );
};
