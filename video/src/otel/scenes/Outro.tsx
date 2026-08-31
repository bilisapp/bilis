import React from "react";
import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from "remotion";
import {
  BilisMark,
  EASE,
  neutral,
  sans,
  severity,
  Stage,
  Title,
  type,
} from "../../brand";

const CHECKLIST = [
  "composer require keepsuit/laravel-opentelemetry",
  "Four lines of .env, ending the endpoint in /api",
  "Requests, queries, jobs and cache — no code changes",
  "Traces over OTLP, logs over the Bilis channel",
  "Ratio sampling, with every error and slow trace kept",
  "One tap, and every log line links to its span",
];

export const Outro: React.FC = () => {
  const frame = useCurrentFrame();

  return (
    <Stage>
      <AbsoluteFill
        name="Outro"
        style={{
          justifyContent: "center",
          alignItems: "center",
          gap: 46,
          padding: 120,
        }}
      >
        <Title delay={0}>Six steps, start to finish</Title>

        <div style={{ display: "flex", flexDirection: "column", gap: 18 }}>
          {CHECKLIST.map((item, index) => {
            const delay = 16 + index * 8;

            return (
              <div
                key={item}
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 20,
                  fontFamily: sans,
                  fontSize: type.body,
                  color: neutral.foreground,
                  opacity: interpolate(frame, [delay, delay + 12], [0, 1], {
                    extrapolateLeft: "clamp",
                    extrapolateRight: "clamp",
                    easing: Easing.bezier(...EASE),
                  }),
                  translate: interpolate(
                    frame,
                    [delay, delay + 16],
                    ["-18px 0px", "0px 0px"],
                    {
                      extrapolateLeft: "clamp",
                      extrapolateRight: "clamp",
                      easing: Easing.bezier(...EASE),
                    },
                  ),
                }}
              >
                <span style={{ color: severity.debug, fontSize: 34 }}>✓</span>
                {item}
              </div>
            );
          })}
        </div>

        <div
          style={{
            display: "flex",
            flexDirection: "column",
            alignItems: "center",
            gap: 26,
            marginTop: 30,
            opacity: interpolate(frame, [78, 96], [0, 1], {
              extrapolateLeft: "clamp",
              extrapolateRight: "clamp",
              easing: Easing.bezier(...EASE),
            }),
          }}
        >
          <BilisMark width={300} still />
          <div
            style={{
              fontFamily: sans,
              fontSize: type.heading,
              color: neutral.mutedForeground,
              letterSpacing: -0.6,
            }}
          >
            bilis.app/docs/ingestion/traces
          </div>
        </div>
      </AbsoluteFill>
    </Stage>
  );
};
