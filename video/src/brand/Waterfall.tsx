import React from "react";
import { Easing, Interactive, interpolate, useCurrentFrame } from "remotion";
import { mono, sans } from "./fonts";
import { useFormat } from "./format";
import { EASE, chart, neutral, severity } from "./theme";

export type Span = {
  name: string;
  service: string;
  /** Offset and length as fractions of the root span's duration. */
  start: number;
  width: number;
  depth: number;
  ms: number;
  failed?: boolean;
};

/**
 * A span waterfall, drawn the way the application draws one.
 *
 * The two rules that matter both come from the design system. A bar is
 * coloured by **service**, out of the chart series — a service is a data
 * series, so the waterfall spends that palette rather than inventing its own —
 * and a failed span overrides to the error severity, because "this broke"
 * outranks "this belongs to payments".
 *
 * The bar's *length* already encodes duration, so the magnitude scale never
 * touches it: two encodings of one quantity on one row fight.
 */
export const Waterfall: React.FC<{
  spans: Span[];
  delay?: number;
  /** Frames between rows drawing in. */
  stagger?: number;
  width?: number;
  /** Width of the span-name gutter. Narrow it on a portrait frame. */
  labelWidth?: number;
  rowHeight?: number;
  /** Hide the per-span duration column when the frame is too narrow for it. */
  showDurations?: boolean;
}> = ({
  spans,
  delay = 0,
  stagger = 5,
  width,
  labelWidth = 560,
  rowHeight = 54,
  showDurations = true,
}) => {
  const frame = useCurrentFrame();
  const format = useFormat();

  const services = [...new Set(spans.map((s) => s.service))];
  const colourOf = (span: Span) =>
    span.failed
      ? severity.error
      : chart[services.indexOf(span.service) % chart.length];

  return (
    <Interactive.Div
      name="Span waterfall"
      style={{
        width: width ?? format.width - format.margin * 2,
        backgroundColor: neutral.card,
        border: `1px solid ${neutral.border}`,
        borderRadius: 18,
        padding: "30px 34px",
        flexShrink: 0,
        opacity: interpolate(frame, [delay, delay + 14], [0, 1], {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
          easing: Easing.bezier(...EASE),
        }),
      }}
    >
      {/* Legend: which colour is which service, above the chart as in the app. */}
      <div
        style={{
          display: "flex",
          gap: 28,
          marginBottom: 26,
          fontFamily: sans,
          fontSize: format.type.label,
          color: neutral.mutedForeground,
        }}
      >
        {services.map((service, index) => (
          <span
            key={service}
            style={{ display: "flex", alignItems: "center", gap: 10 }}
          >
            <span
              style={{
                width: 14,
                height: 14,
                borderRadius: 4,
                backgroundColor: chart[index % chart.length],
              }}
            />
            {service}
          </span>
        ))}
      </div>

      {spans.map((span, index) => {
        const rowDelay = delay + 6 + index * stagger;

        return (
          <div
            key={index}
            style={{
              display: "flex",
              alignItems: "center",
              height: rowHeight,
              borderTop: index === 0 ? "none" : `1px solid ${neutral.muted}`,
              opacity: interpolate(frame, [rowDelay, rowDelay + 10], [0, 1], {
                extrapolateLeft: "clamp",
                extrapolateRight: "clamp",
                easing: Easing.bezier(...EASE),
              }),
            }}
          >
            <div
              style={{
                width: labelWidth,
                paddingLeft: span.depth * 34,
                fontFamily: mono,
                fontSize: format.type.label,
                color: span.failed ? severity.error : neutral.foreground,
                whiteSpace: "nowrap",
                overflow: "hidden",
                textOverflow: "ellipsis",
              }}
            >
              {span.name}
            </div>

            <div style={{ flex: 1, position: "relative", height: 26 }}>
              <div
                style={{
                  position: "absolute",
                  left: `${span.start * 100}%`,
                  height: "100%",
                  borderRadius: 6,
                  backgroundColor: colourOf(span),
                  /* Bars grow from their own start, so the eye reads causality. */
                  width: `${
                    span.width *
                    100 *
                    interpolate(frame, [rowDelay + 4, rowDelay + 20], [0, 1], {
                      extrapolateLeft: "clamp",
                      extrapolateRight: "clamp",
                      easing: Easing.bezier(...EASE),
                    })
                  }%`,
                }}
              />
            </div>

            {showDurations ? (
              <div
                style={{
                  width: 130,
                  textAlign: "right",
                  fontFamily: mono,
                  fontSize: format.type.label,
                  color: neutral.mutedForeground,
                }}
              >
                {span.ms} ms
              </div>
            ) : null}
          </div>
        );
      })}
    </Interactive.Div>
  );
};
