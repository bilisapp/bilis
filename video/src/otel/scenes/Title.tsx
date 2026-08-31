import React from "react";
import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from "remotion";
import {
  BilisMark,
  Display,
  EASE,
  neutral,
  sans,
  Stage,
  type,
} from "../../brand";

export const Title: React.FC = () => {
  const frame = useCurrentFrame();

  return (
    <Stage>
      <AbsoluteFill
        name="Title card"
        style={{
          justifyContent: "center",
          alignItems: "center",
          gap: 60,
          padding: 120,
          textAlign: "center",
        }}
      >
        <BilisMark width={560} delay={4} />

        <Display delay={30}>Tracing Laravel with Bilis</Display>

        <div
          style={{
            fontFamily: sans,
            fontSize: type.heading,
            color: neutral.mutedForeground,
            letterSpacing: -0.8,
            opacity: interpolate(frame, [46, 64], [0, 1], {
              extrapolateLeft: "clamp",
              extrapolateRight: "clamp",
              easing: Easing.bezier(...EASE),
            }),
          }}
        >
          Full request traces in six steps — no PHP extension
        </div>
      </AbsoluteFill>
    </Stage>
  );
};
