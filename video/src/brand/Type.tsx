import React from "react";
import { Easing, Interactive, interpolate, useCurrentFrame } from "remotion";
import { sans } from "./fonts";
import { useFormat } from "./format";
import { EASE, neutral } from "./theme";

/**
 * One entrance, used by every piece of text in the template.
 *
 * A short rise and a fade on the project's single easing curve. Nothing
 * bounces, nothing scales: the interface has no decorative motion, and neither
 * does the video that explains it.
 */
export const Rise: React.FC<{
  children: React.ReactNode;
  delay?: number;
  name?: string;
  style?: React.CSSProperties;
}> = ({ children, delay = 0, name = "Rise", style }) => {
  const frame = useCurrentFrame();

  return (
    <Interactive.Div
      name={name}
      style={{
        /* Never squash: an overflowing scene must look wrong, not compress. */
        flexShrink: 0,
        opacity: interpolate(frame, [delay, delay + 16], [0, 1], {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
          easing: Easing.bezier(...EASE),
        }),
        translate: interpolate(
          frame,
          [delay, delay + 20],
          ["0px 26px", "0px 0px"],
          {
            extrapolateLeft: "clamp",
            extrapolateRight: "clamp",
            easing: Easing.bezier(...EASE),
          },
        ),
        ...style,
      }}
    >
      {children}
    </Interactive.Div>
  );
};

export const Display: React.FC<{
  children: React.ReactNode;
  delay?: number;
}> = ({ children, delay = 0 }) => {
  const format = useFormat();

  return (
  <Rise
    name="Display"
    delay={delay}
    style={{
      fontFamily: sans,
      fontSize: format.type.display,
      fontWeight: 600,
      lineHeight: 1.05,
      letterSpacing: -2.5,
      color: neutral.foreground,
      maxWidth: format.width - format.margin * 2,
    }}
  >
    {children}
  </Rise>
  );
};

export const Title: React.FC<{
  children: React.ReactNode;
  delay?: number;
}> = ({ children, delay = 0 }) => {
  const format = useFormat();

  return (
  <Rise
    name="Title"
    delay={delay}
    style={{
      fontFamily: sans,
      fontSize: format.type.title,
      fontWeight: 600,
      lineHeight: 1.12,
      letterSpacing: -1.8,
      color: neutral.foreground,
      maxWidth: format.width - format.margin * 2,
    }}
  >
    {children}
  </Rise>
  );
};

export const Body: React.FC<{
  children: React.ReactNode;
  delay?: number;
  muted?: boolean;
}> = ({ children, delay = 0, muted = true }) => {
  const format = useFormat();

  return (
  <Rise
    name="Body"
    delay={delay}
    style={{
      fontFamily: sans,
      fontSize: format.type.body,
      lineHeight: 1.5,
      letterSpacing: -0.3,
      color: muted ? neutral.mutedForeground : neutral.foreground,
      maxWidth: format.width - format.margin * 2,
    }}
  >
    {children}
  </Rise>
  );
};

/**
 * Emphasis inside body copy.
 *
 * Weight and the full-contrast foreground, never a colour — there is no accent
 * hue in this system, and borrowing a data colour to shout with would make the
 * one on the next slide mean less.
 */
export const Strong: React.FC<{ children: React.ReactNode }> = ({
  children,
}) => (
  <span style={{ color: neutral.foreground, fontWeight: 600 }}>{children}</span>
);
