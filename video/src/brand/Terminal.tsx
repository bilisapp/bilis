import React from "react";
import { Easing, Interactive, interpolate, useCurrentFrame } from "remotion";
import { mono, sans } from "./fonts";
import { useFormat } from "./format";
import { EASE, mark, neutral, severity } from "./theme";

export type TerminalLine =
  | { kind: "prompt"; text: string }
  /** A command wrapped onto a second line: same colour, no second prompt. */
  | { kind: "cont"; text: string }
  | { kind: "out"; text: string }
  | { kind: "error"; text: string }
  | { kind: "ok"; text: string };

/**
 * A terminal panel where output arrives on a schedule.
 *
 * Errors take the error severity because that is exactly what they are — the
 * one place in this template where a red is not decoration. Success takes the
 * debug teal rather than a green invented for the occasion.
 */
export const Terminal: React.FC<{
  lines: TerminalLine[];
  title?: string;
  delay?: number;
  /** Frames between lines. Output should land slower than a code reveal. */
  stagger?: number;
  fontSize?: number;
}> = ({
  lines,
  title = "zsh",
  delay = 0,
  stagger = 10,
  fontSize,
}) => {
  const frame = useCurrentFrame();
  const format = useFormat();
  const size = fontSize ?? format.type.code;

  const colourFor = (kind: TerminalLine["kind"]) => {
    if (kind === "error") {
return severity.error;
}

    if (kind === "ok") {
return severity.debug;
}

    if (kind === "prompt" || kind === "cont") {
return neutral.foreground;
}

    return neutral.mutedForeground;
  };

  return (
    <Interactive.Div
      name={`Terminal · ${title}`}
      style={{
        backgroundColor: neutral.sidebar,
        border: `1px solid ${neutral.border}`,
        borderRadius: 18,
        overflow: "hidden",
        flexShrink: 0,
        opacity: interpolate(frame, [delay, delay + 14], [0, 1], {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
          easing: Easing.bezier(...EASE),
        }),
        translate: interpolate(
          frame,
          [delay, delay + 20],
          ["0px 22px", "0px 0px"],
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
          padding: "18px 30px",
          borderBottom: `1px solid ${neutral.border}`,
          fontFamily: sans,
          fontSize: format.type.label,
          color: neutral.mutedForeground,
        }}
      >
        {[0, 1, 2].map((dot) => (
          <span
            key={dot}
            style={{
              width: 12,
              height: 12,
              borderRadius: 999,
              backgroundColor: neutral.input,
            }}
          />
        ))}
        <span style={{ marginLeft: 12 }}>{title}</span>
      </div>

      <div
        style={{
          padding: "28px 34px",
          fontFamily: mono,
          fontSize: size,
          lineHeight: 1.7,
          whiteSpace: "pre-wrap",
        }}
      >
        {lines.map((line, index) => {
          const lineDelay = delay + 10 + index * stagger;

          return (
            <div
              key={index}
              style={{
                color: colourFor(line.kind),
                opacity: interpolate(
                  frame,
                  [lineDelay, lineDelay + 8],
                  [0, 1],
                  {
                    extrapolateLeft: "clamp",
                    extrapolateRight: "clamp",
                    easing: Easing.bezier(...EASE),
                  },
                ),
              }}
            >
              {line.kind === "prompt" ? (
                <span style={{ color: mark.gold, marginRight: 14 }}>❯</span>
              ) : null}
              {line.text}
            </div>
          );
        })}
      </div>
    </Interactive.Div>
  );
};
