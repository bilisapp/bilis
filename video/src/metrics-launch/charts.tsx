import React from "react";
import { random, useCurrentFrame } from "remotion";
import { mono, neutral } from "../brand";
import { tween } from "./kit";

/**
 * Charts drawn the way the metric explorer draws them — lines coloured by
 * series from the chart palette, a faint area under each, gridlines in the
 * border tone — but animated: the stroke draws left to right, the area is
 * revealed behind it, and the leading point glows in its own series colour.
 * That glow is the only light in the film that is not the neutral ladder, and
 * it is data.
 */

export type Series = { label: string; color: string; values: number[] };

/** A label made safe for an SVG id: `url(#…)` breaks on spaces, slashes and dots. */
const idSafe = (value: string): string => value.replace(/[^A-Za-z0-9_-]/g, "_");

/** A deterministic, smooth-ish series: a base, a drift, and seeded wobble. */
export const makeSeries = (
  seed: string,
  points: number,
  base: number,
  { drift = 0, wobble = 0.12, spike }: { drift?: number; wobble?: number; spike?: { at: number; by: number } } = {},
): number[] => {
  const values: number[] = [];
  let last = base;

  for (let index = 0; index < points; index++) {
    const noise = (random(`${seed}-${index}`) - 0.5) * 2 * wobble * base;
    const target = base + drift * (index / points) * base + noise;
    last = last * 0.55 + target * 0.45;
    const bump = spike ? Math.max(0, 1 - Math.abs(index - spike.at) / 4) * spike.by * base : 0;
    values.push(Math.max(0, last + bump));
  }

  return values;
};

/** Catmull-Rom through the points, as cubic Béziers: smooth, never overshooting much. */
const smoothPath = (points: [number, number][]): string => {
  if (points.length < 2) {
    return "";
  }

  let d = `M ${points[0][0]} ${points[0][1]}`;

  for (let index = 0; index < points.length - 1; index++) {
    const p0 = points[index - 1] ?? points[index];
    const p1 = points[index];
    const p2 = points[index + 1];
    const p3 = points[index + 2] ?? p2;
    const c1: [number, number] = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6];
    const c2: [number, number] = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
    d += ` C ${c1[0]} ${c1[1]}, ${c2[0]} ${c2[1]}, ${p2[0]} ${p2[1]}`;
  }

  return d;
};

export const LineChart: React.FC<{
  series: Series[];
  width: number;
  height: number;
  /** Frame the drawing starts, and how long it takes. */
  delay?: number;
  frames?: number;
  /** Frames between one series and the next. */
  stagger?: number;
  yMax: number;
  yTicks?: number;
  yFormat?: (value: number) => string;
  xLabels?: string[];
  area?: boolean;
  strokeWidth?: number;
  /** Series drawn at this opacity: for a chart that is context, not subject. */
  fade?: number;
  /** Moments to point at: a dashed rule at a point index, with a label, in a data colour. */
  markers?: { index: number; count: number; label: string; color: string; delay: number; value?: number }[];
  /** Room for the y-axis labels; 0 for an axis-less chart. */
  gutter?: number;
}> = ({
  series,
  width,
  height,
  delay = 0,
  frames = 60,
  stagger = 6,
  yMax,
  yTicks = 4,
  yFormat = (v) => String(Math.round(v)),
  xLabels = [],
  area = true,
  strokeWidth = 4,
  fade = 1,
  markers = [],
  gutter = 92,
}) => {
  const frame = useCurrentFrame();
  const bottom = xLabels.length > 0 ? 44 : 8;
  const plotW = width - gutter;
  const plotH = height - bottom;
  const x = (index: number, count: number) => gutter + (index / (count - 1)) * plotW;
  const y = (value: number) => plotH - (value / yMax) * (plotH - 10);

  return (
    <svg width={width} height={height} style={{ overflow: "visible" }}>
      <defs>
        {series.map((line, index) => (
          <linearGradient key={line.label} id={`area-${index}-${idSafe(line.label)}`} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={line.color} stopOpacity={0.28} />
            <stop offset="100%" stopColor={line.color} stopOpacity={0} />
          </linearGradient>
        ))}
        <filter id="glow" x="-50%" y="-50%" width="200%" height="200%">
          <feGaussianBlur stdDeviation="6" />
        </filter>
      </defs>

      {Array.from({ length: yTicks + 1 }, (_, tick) => {
        const value = (yMax / yTicks) * tick;
        const lineY = y(value);

        return (
          <g key={tick} opacity={tween(frame, [delay - 10, delay + 4], [0, 1])}>
            <line x1={gutter} x2={width} y1={lineY} y2={lineY} stroke={neutral.border} strokeWidth={1.5} strokeDasharray={tick === 0 ? undefined : "2 10"} />
            <text x={gutter - 18} y={lineY + 8} textAnchor="end" fontFamily={mono} fontSize={22} fill={neutral.mutedForeground}>
              {yFormat(value)}
            </text>
          </g>
        );
      })}

      {xLabels.map((label, index) => (
        <text
          key={label}
          x={gutter + (index / Math.max(1, xLabels.length - 1)) * plotW}
          y={height - 6}
          textAnchor={index === 0 ? "start" : index === xLabels.length - 1 ? "end" : "middle"}
          fontFamily={mono}
          fontSize={21}
          fill={neutral.mutedForeground}
          opacity={tween(frame, [delay - 10, delay + 4], [0, 1])}
        >
          {label}
        </text>
      ))}

      {series.map((line, index) => {
        const start = delay + index * stagger;
        const progress = tween(frame, [start, start + frames], [0, 1]);
        const points = line.values.map((value, i) => [x(i, line.values.length), y(value)] as [number, number]);
        const d = smoothPath(points);
        const head = progress * (line.values.length - 1);
        const i0 = Math.floor(head);
        const i1 = Math.min(line.values.length - 1, i0 + 1);
        const headValue = line.values[i0] + (line.values[i1] - line.values[i0]) * (head - i0);
        const headX = gutter + progress * plotW;
        const headY = y(headValue);
        const clipId = `clip-${index}-${idSafe(line.label)}`;

        return (
          <g key={line.label} opacity={fade}>
            <clipPath id={clipId}>
              <rect x={0} y={-20} width={headX} height={height + 40} />
            </clipPath>
            {area ? (
              <path d={`${d} L ${gutter + plotW} ${plotH} L ${gutter} ${plotH} Z`} fill={`url(#area-${index}-${idSafe(line.label)})`} clipPath={`url(#${clipId})`} />
            ) : null}
            <path d={d} fill="none" stroke={line.color} strokeWidth={strokeWidth + 6} strokeOpacity={0.35} filter="url(#glow)" clipPath={`url(#${clipId})`} />
            <path d={d} fill="none" stroke={line.color} strokeWidth={strokeWidth} strokeLinecap="round" clipPath={`url(#${clipId})`} />
            {progress > 0 ? (
              <g>
                <circle cx={headX} cy={headY} r={16} fill={line.color} opacity={0.25} filter="url(#glow)" />
                <circle cx={headX} cy={headY} r={7} fill={line.color} stroke={neutral.card} strokeWidth={3} />
              </g>
            ) : null}
          </g>
        );
      })}

      {markers.map((marker) => {
        const markerX = x(marker.index, marker.count);
        const shown = tween(frame, [marker.delay, marker.delay + 12], [0, 1]);
        const labelWidth = marker.label.length * 14 + 36;
        const labelX = Math.min(width - labelWidth, Math.max(gutter, markerX - labelWidth / 2));
        const dotY = marker.value === undefined ? null : y(marker.value);

        return (
          <g key={marker.label} opacity={shown}>
            <line x1={markerX} x2={markerX} y1={30} y2={plotH} stroke={marker.color} strokeWidth={2} strokeDasharray="6 8" />
            {dotY === null ? null : (
              <g>
                <circle cx={markerX} cy={dotY} r={22 * shown} fill={marker.color} opacity={0.22} filter="url(#glow)" />
                <circle cx={markerX} cy={dotY} r={8} fill={marker.color} stroke={neutral.card} strokeWidth={3} />
              </g>
            )}
            <g transform={`translate(${labelX} ${-8 + (1 - shown) * 10})`}>
              <rect width={labelWidth} height={40} rx={20} fill={neutral.popover} stroke={marker.color} strokeOpacity={0.6} />
              <text x={labelWidth / 2} y={27} textAnchor="middle" fontFamily={mono} fontSize={22} fill={neutral.foreground}>
                {marker.label}
              </text>
            </g>
          </g>
        );
      })}
    </svg>
  );
};

/**
 * A histogram that stands up, then hands the stage to its percentiles.
 *
 * The bars are the raw buckets — how many requests fell in each latency range
 * — and they dim as the p50/p95/p99 lines draw over time: the same data, read
 * the way the explorer reads it.
 */
export const HistogramToPercentiles: React.FC<{
  buckets: number[];
  bounds: string[];
  width: number;
  height: number;
  delay?: number;
  handoffAt: number;
  percentiles: Series[];
  yMax: number;
  yFormat: (value: number) => string;
  xLabels: string[];
}> = ({ buckets, bounds, width, height, delay = 0, handoffAt, percentiles, yMax, yFormat, xLabels }) => {
  const frame = useCurrentFrame();
  const max = Math.max(...buckets);
  const barGap = 14;
  const barArea = height - 56;
  const barW = (width - barGap * (buckets.length - 1)) / buckets.length;
  const dim = tween(frame, [handoffAt, handoffAt + 18], [1, 0]);

  return (
    <div style={{ position: "relative", width, height }}>
      <div style={{ position: "absolute", inset: 0, opacity: dim, display: "flex", alignItems: "flex-end", gap: barGap }}>
        {buckets.map((count, index) => {
          const at = delay + index * 3;
          const grown = tween(frame, [at, at + 22], [0, count / max]);

          return (
            <div key={index} style={{ width: barW, display: "flex", flexDirection: "column", alignItems: "center", gap: 14 }}>
              <div
                style={{
                  width: "100%",
                  height: grown * barArea,
                  borderRadius: "10px 10px 3px 3px",
                  background: `linear-gradient(180deg, ${neutral.ring}, ${neutral.input})`,
                }}
              />
              <div style={{ fontFamily: mono, fontSize: 20, color: neutral.mutedForeground }}>{bounds[index]}</div>
            </div>
          );
        })}
      </div>
      <div style={{ position: "absolute", inset: 0, opacity: tween(frame, [handoffAt + 6, handoffAt + 18], [0, 1]) }}>
        <LineChart
          series={percentiles}
          width={width}
          height={height}
          delay={handoffAt + 8}
          frames={50}
          stagger={5}
          yMax={yMax}
          yFormat={yFormat}
          xLabels={xLabels}
          area={false}
        />
      </div>
    </div>
  );
};

/** A small line, no axes: the trend inside a stat card. */
export const Sparkline: React.FC<{
  values: number[];
  color: string;
  width: number;
  height: number;
  delay?: number;
}> = ({ values, color, width, height, delay = 0 }) => {
  const frame = useCurrentFrame();
  const max = Math.max(...values) * 1.15;
  const min = Math.min(...values) * 0.85;
  const points = values.map((value, index) => [
    (index / (values.length - 1)) * width,
    height - ((value - min) / (max - min)) * height,
  ] as [number, number]);
  const progress = tween(frame, [delay, delay + 40], [0, 1]);
  const d = smoothPath(points);
  const id = `spark-${color.replace(/[^\w]/g, "")}`;

  return (
    <svg width={width} height={height} style={{ overflow: "visible" }}>
      <defs>
        <linearGradient id={`${id}-fill`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity={0.3} />
          <stop offset="100%" stopColor={color} stopOpacity={0} />
        </linearGradient>
        <clipPath id={`${id}-clip`}>
          <rect x={0} y={-10} width={progress * width} height={height + 20} />
        </clipPath>
      </defs>
      <path d={`${d} L ${width} ${height} L 0 ${height} Z`} fill={`url(#${id}-fill)`} clipPath={`url(#${id}-clip)`} />
      <path d={d} fill="none" stroke={color} strokeWidth={3} strokeLinecap="round" clipPath={`url(#${id}-clip)`} />
    </svg>
  );
};
