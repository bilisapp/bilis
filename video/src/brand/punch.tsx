import React from "react";
import {
  AbsoluteFill,
  Easing,
  Interactive,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { sans } from "./fonts";
import { mark, neutral } from "./theme";

/**
 * Retention mechanics for the high-energy Short.
 *
 * These deliberately break the calm rules the rest of the template follows —
 * the interface has no decorative motion, and everything here is decorative
 * motion. They are quarantined in this file, and used by exactly one
 * composition, so the restraint everywhere else stays intact.
 *
 * What actually holds a viewer on a Short is not "more animation", it is never
 * letting a frame sit still long enough to be scrolled past: a cut every one
 * to three seconds, a scale punch on every cut, text that arrives word by word
 * rather than all at once, and a visible sense of progress.
 */

/**
 * The scale punch on a hard cut.
 *
 * Every scene starts very slightly too big and settles. It is barely visible
 * frame to frame and it is most of why fast cuts feel deliberate rather than
 * jumpy.
 */
export const PunchIn: React.FC<{
  children: React.ReactNode;
  /** How far in to start. 1.08 is a shove; 1.03 is a nudge. */
  from?: number;
  frames?: number;
}> = ({ children, from = 1.06, frames = 18 }) => {
  const frame = useCurrentFrame();

  return (
    <AbsoluteFill
      style={{
        scale: interpolate(frame, [0, frames], [from, 1], {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
          easing: Easing.bezier(0.16, 1, 0.3, 1),
          output: "perceptual-scale",
        }),
      }}
    >
      {children}
    </AbsoluteFill>
  );
};

/**
 * Kinetic captions: one word at a time, each landing on a spring.
 *
 * The reason this works is that a viewer cannot pre-read the sentence, so the
 * line finishes in their head a beat after it finishes on screen — which is
 * exactly long enough to not scroll.
 */
export const WordPop: React.FC<{
  text: string;
  delay?: number;
  /** Frames between words. Under 4 is a blur; over 8 loses the urgency. */
  per?: number;
  size?: number;
  color?: string;
  /** Words to hit in the mark's gold, for a colour beat mid-sentence. */
  accent?: string[];
  align?: "center" | "flex-start";
}> = ({
  text,
  delay = 0,
  per = 5,
  size = 118,
  color = neutral.foreground,
  accent = [],
  align = "center",
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const words = text.split(" ");

  return (
    <Interactive.Div
      name={`WordPop · ${text.slice(0, 24)}`}
      style={{
        display: "flex",
        flexWrap: "wrap",
        justifyContent: align,
        alignItems: "baseline",
        gap: `${size * 0.1}px ${size * 0.22}px`,
        fontFamily: sans,
        fontSize: size,
        fontWeight: 700,
        letterSpacing: -size * 0.045,
        lineHeight: 1.02,
        textAlign: align === "center" ? "center" : "left",
      }}
    >
      {words.map((word, index) => {
        const at = delay + index * per;
        const bare = word.replace(/[^A-Za-z0-9]/g, "");

        return (
          <span
            key={`${word}-${index}`}
            style={{
              display: "inline-block",
              color: accent.includes(bare) ? mark.gold : color,
              opacity: interpolate(frame, [at, at + 3], [0, 1], {
                extrapolateLeft: "clamp",
                extrapolateRight: "clamp",
              }),
              scale: spring({
                frame: frame - at,
                fps,
                config: { damping: 12, stiffness: 220, mass: 0.6 },
                durationInFrames: 22,
              }),
            }}
          >
            {word}
          </span>
        );
      })}
    </Interactive.Div>
  );
};

/**
 * A progress bar across the top.
 *
 * Pure retention furniture: it answers "how much is left" before the viewer
 * thinks to ask, which is the moment they would otherwise swipe.
 */
export const Ticker: React.FC<{ total: number }> = ({ total }) => {
  const frame = useCurrentFrame();

  return (
    <AbsoluteFill style={{ justifyContent: "flex-start" }}>
      <div style={{ height: 10, backgroundColor: neutral.muted }}>
        <div
          style={{
            height: "100%",
            width: `${interpolate(frame, [0, total], [0, 100], {
              extrapolateLeft: "clamp",
              extrapolateRight: "clamp",
            })}%`,
            background: `linear-gradient(90deg, ${mark.gold}, ${mark.teal})`,
          }}
        />
      </div>
    </AbsoluteFill>
  );
};

/**
 * A big step chip. Loud, because it is the thing promising the video is short.
 */
export const StepChip: React.FC<{ label: string; delay?: number }> = ({
  label,
  delay = 0,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  return (
    <div
      style={{
        alignSelf: "center",
        backgroundColor: mark.gold,
        color: neutral.background,
        fontFamily: sans,
        fontSize: 46,
        fontWeight: 700,
        letterSpacing: 1,
        padding: "12px 34px",
        borderRadius: 999,
        scale: spring({
          frame: frame - delay,
          fps,
          config: { damping: 11, stiffness: 200 },
          durationInFrames: 20,
        }),
      }}
    >
      {label}
    </div>
  );
};
