import React from "react";
import { spring, useCurrentFrame, useVideoConfig } from "remotion";
import { chart, mono, neutral, sans, severity } from "../brand";
import { LineChart, makeSeries } from "./charts";
import { tween } from "./kit";

/**
 * Illustrations for viewers who have never seen Bilis: a phone waking up at
 * 3 AM (the hook everyone on call has lived), and three miniature product
 * panels — logs, traces, metrics — that say what Bilis is in one glance.
 *
 * Built from the same tokens as the interface, so an illustration never
 * introduces a colour the product would not use: chrome from the neutral
 * ladder, hue only where it is data.
 */

export type Notification = { app: string; glyph: string; title: string; body: string; at: number };

/**
 * A lock screen receiving notifications.
 *
 * Each one drops in on a spring and the phone gives one short haptic buzz as
 * it lands — a decaying shake that is over in a third of a second, so the
 * resting frame is perfectly still.
 */
export const Phone: React.FC<{ notifications: Notification[]; delay?: number }> = ({ notifications, delay = 0 }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const buzz = notifications.reduce((offset, note) => {
    const since = frame - note.at;

    if (since < 0 || since > 10) {
      return offset;
    }

    return offset + Math.sin(since * 2.6) * 7 * (1 - since / 10);
  }, 0);

  return (
    <div
      style={{
        width: 470,
        height: 940,
        borderRadius: 72,
        padding: 16,
        background: `linear-gradient(160deg, ${neutral.input}, ${neutral.muted})`,
        boxShadow: "0 70px 160px -30px hsl(225 30% 2% / 0.9), 0 0 0 1px hsl(225 20% 100% / 0.06) inset",
        opacity: tween(frame, [delay, delay + 14], [0, 1]),
        translate: `${buzz}px ${tween(frame, [delay, delay + 30], [60, 0])}px`,
        rotate: `${tween(frame, [delay, delay + 40], [-6, -3])}deg`,
      }}
    >
      <div
        style={{
          position: "relative",
          width: "100%",
          height: "100%",
          borderRadius: 58,
          overflow: "hidden",
          background: `radial-gradient(ellipse 120% 70% at 50% 0%, ${neutral.accent}, ${neutral.sidebar} 70%)`,
        }}
      >
        <div style={{ position: "absolute", top: 22, left: "50%", width: 130, height: 36, marginLeft: -65, borderRadius: 20, backgroundColor: neutral.sidebar }} />
        <div style={{ paddingTop: 120, textAlign: "center", fontFamily: sans, color: neutral.foreground }}>
          <div style={{ fontSize: 26, color: neutral.mutedForeground, fontWeight: 500 }}>Thursday, 25 September</div>
          <div style={{ fontSize: 132, fontWeight: 600, letterSpacing: -5, lineHeight: 1.05 }}>3:07</div>
        </div>
        <div style={{ position: "absolute", left: 18, right: 18, top: 360, display: "flex", flexDirection: "column", gap: 12 }}>
          {notifications.map((note) => {
            const landed = spring({ frame: frame - note.at, fps, config: { damping: 16, stiffness: 170 } });

            return (
              <div
                key={note.title}
                style={{
                  display: "flex",
                  gap: 16,
                  padding: "18px 20px",
                  borderRadius: 26,
                  backgroundColor: neutral.popover,
                  border: "1px solid hsl(225 20% 100% / 0.06)",
                  opacity: frame < note.at ? 0 : Math.min(1, landed * 1.4),
                  translate: `0px ${(1 - landed) * -70}px`,
                  scale: 0.94 + landed * 0.06,
                }}
              >
                <div style={{ width: 52, height: 52, flexShrink: 0, borderRadius: 14, backgroundColor: neutral.accent, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 28 }}>
                  {note.glyph}
                </div>
                <div style={{ display: "flex", flexDirection: "column", gap: 3, minWidth: 0 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", fontFamily: sans, fontSize: 19, color: neutral.mutedForeground }}>
                    <span style={{ textTransform: "uppercase", letterSpacing: 1 }}>{note.app}</span>
                    <span>now</span>
                  </div>
                  <div style={{ fontFamily: sans, fontSize: 23, fontWeight: 600, color: neutral.foreground }}>{note.title}</div>
                  <div style={{ fontFamily: sans, fontSize: 21, color: neutral.mutedForeground, lineHeight: 1.3 }}>{note.body}</div>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
};

/** A few log rows, the way the log viewer shows them. */
export const MiniLogs: React.FC<{ delay: number }> = ({ delay }) => {
  const frame = useCurrentFrame();
  const rows: [keyof typeof severity, string][] = [
    ["info", "POST /checkout 200"],
    ["warn", "Slow request · 2,841 ms"],
    ["error", "Charge timed out"],
    ["info", "Order 41902 created"],
    ["debug", "Cache hit cart:7781"],
  ];

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10, fontFamily: mono, fontSize: 19 }}>
      {rows.map(([level, text], index) => (
        <div key={index} style={{ display: "flex", gap: 14, opacity: tween(frame, [delay + index * 4, delay + index * 4 + 8], [0, 1]) }}>
          <span style={{ width: 62, color: severity[level], fontWeight: 600 }}>{level.toUpperCase()}</span>
          <span style={{ color: neutral.codeForeground, whiteSpace: "nowrap" }}>{text}</span>
        </div>
      ))}
    </div>
  );
};

/** A small span waterfall: bars keyed by service colour, one failed span in the error tone. */
export const MiniWaterfall: React.FC<{ delay: number }> = ({ delay }) => {
  const frame = useCurrentFrame();
  const spans: { start: number; width: number; color: string; name: string }[] = [
    { start: 0, width: 1, color: chart[0], name: "POST /checkout" },
    { start: 0.06, width: 0.16, color: chart[0], name: "SELECT cart" },
    { start: 0.26, width: 0.5, color: chart[1], name: "charge card" },
    { start: 0.36, width: 0.3, color: chart[1], name: "card network" },
    { start: 0.8, width: 0.16, color: severity.error, name: "INSERT order" },
  ];

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
      {spans.map((span, index) => {
        const at = delay + index * 4;

        return (
          <div key={span.name} style={{ position: "relative", height: 26 }}>
            <div
              style={{
                position: "absolute",
                left: `${span.start * 100}%`,
                width: `${span.width * 100 * tween(frame, [at, at + 16], [0, 1])}%`,
                height: "100%",
                borderRadius: 6,
                backgroundColor: span.color,
                opacity: 0.9,
              }}
            />
            <span style={{ position: "absolute", left: `calc(${span.start * 100}% + 10px)`, top: 2, fontFamily: mono, fontSize: 17, color: neutral.background, fontWeight: 600, whiteSpace: "nowrap", opacity: tween(frame, [at + 8, at + 14], [0, 1]) }}>
              {span.width > 0.4 ? span.name : ""}
            </span>
          </div>
        );
      })}
    </div>
  );
};

/** A small chart: the metrics card. */
export const MiniChart: React.FC<{ delay: number; width: number }> = ({ delay, width }) => (
  <LineChart
    series={[
      { label: "mini-a", color: chart[0], values: makeSeries("mini-a", 24, 60, { wobble: 0.18, spike: { at: 17, by: 0.5 } }) },
      { label: "mini-b", color: chart[2], values: makeSeries("mini-b", 24, 32, { wobble: 0.15 }) },
    ]}
    width={width}
    height={170}
    delay={delay}
    frames={50}
    yMax={110}
    yTicks={0}
    yFormat={() => ""}
    strokeWidth={3}
    gutter={0}
  />
);
