import React from "react";
import { AbsoluteFill, useCurrentFrame } from "remotion";
import { BilisMark, chart, mark, mono, neutral, sans, severity } from "../brand";
import { LineChart, Sparkline, makeSeries, type Series } from "./charts";
import { MiniChart, MiniLogs, MiniWaterfall, Phone, type Notification } from "./illustrations";
import { Atmosphere, Camera, Chip, Counter, Eyebrow, Headline, Panel, Sub, tween } from "./kit";

/**
 * The metrics launch film, told as one incident instead of a feature tour.
 *
 * 03:07, checkout is slow and the logs cannot say why. Every beat after that
 * is a benefit the viewer gets, in the order they would need it: know why,
 * see it coming, find the cause, get every server covered, get the answer
 * handed to you — without lock-in, free to start. The last frame is the ask.
 *
 * The times agree across scenes on purpose (the CPU saturates at 03:06, p95
 * spikes a minute later, the page fires at 03:07): a viewer who notices is a
 * viewer who is following the story.
 *
 * Every capability shown ships today — histograms as percentiles, counters as
 * rates, the one-line server agent, `query-metric` over MCP, the Free plan's
 * million data points a day. The agent's answer is an illustration of what it
 * does with them, not a recording.
 */

const MARGIN = 120;

/** Faint data lines behind a type-only scene, so it has depth without a panel. */
const Backdrop: React.FC<{ seed: string; delay?: number; color?: string; opacity?: number; top?: number }> = ({
  seed,
  delay = 0,
  color = chart[0],
  opacity = 0.5,
  top = 560,
}) => (
  <AbsoluteFill style={{ top, left: -40, opacity }}>
    <LineChart
      series={[{ label: seed, color, values: makeSeries(seed, 40, 50, { drift: 0.4, wobble: 0.28 }) }]}
      width={2000}
      height={420}
      delay={delay}
      frames={90}
      yMax={100}
      yTicks={0}
      yFormat={() => ""}
      strokeWidth={5}
    />
  </AbsoluteFill>
);

/** A headline block pinned to the left third, the recurring layout of the benefit beats. */
const LeftCopy: React.FC<{ eyebrow: string; lines: Parameters<typeof Headline>[0]["lines"]; sub?: React.ReactNode; top?: number }> = ({
  eyebrow,
  lines,
  sub,
  top = 330,
}) => (
  <AbsoluteFill style={{ left: MARGIN, top, width: 700, gap: 26 }}>
    <Eyebrow delay={0}>{eyebrow}</Eyebrow>
    <Headline size={76} delay={2} lines={lines} />
    {sub ? <Sub delay={22}>{sub}</Sub> : null}
  </AbsoluteFill>
);

/** The explorer's toolbar row, as chips. */
const Toolbar: React.FC<{ metric: string; items: [string, string][]; delay: number }> = ({ metric, items, delay }) => (
  <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
    <Chip delay={delay} size={21} label="metric">
      {metric}
    </Chip>
    {items.map(([label, value], index) => (
      <Chip key={label} size={21} delay={delay + 4 + index * 4} label={label}>
        {value}
      </Chip>
    ))}
  </div>
);

const Legend: React.FC<{ series: { label: string; color: string }[]; delay: number }> = ({ series, delay }) => {
  const frame = useCurrentFrame();

  return (
    <div style={{ display: "flex", gap: 30, opacity: tween(frame, [delay, delay + 12], [0, 1]) }}>
      {series.map((line) => (
        <div key={line.label} style={{ display: "flex", alignItems: "center", gap: 10, fontFamily: mono, fontSize: 22, color: neutral.mutedForeground }}>
          <span style={{ width: 22, height: 4, borderRadius: 2, backgroundColor: line.color }} />
          {line.label}
        </div>
      ))}
    </div>
  );
};

const TIMES = ["02:10", "02:25", "02:40", "02:55", "03:10"];
const POINTS = 40;

/** Checkout's p95: calm, then the climb that paged someone. */
const P95: Series = {
  label: "p95 · POST /checkout",
  color: chart[0],
  values: makeSeries("p95-story", POINTS, 190, { wobble: 0.1, spike: { at: 34, by: 2.1 } }),
};

const NOTIFICATIONS: Notification[] = [
  { app: "Alerts", glyph: "⚠️", title: "Checkout p95 over 2 s", body: "Firing for 4 minutes", at: 26 },
  { app: "Support", glyph: "✉️", title: "14 new tickets", body: "“Payment keeps spinning”", at: 48 },
  { app: "Team chat", glyph: "💬", title: "Marta · #incidents", body: "Is checkout down??", at: 70 },
];

/** 0 · The hook: a phone waking up at 3 AM. Everyone on call has been here. */
export const Hook: React.FC = () => (
  <Atmosphere enterAt={0}>
    <AbsoluteFill style={{ left: 260, top: 70 }}>
      <Phone notifications={NOTIFICATIONS} delay={0} />
    </AbsoluteFill>
    <AbsoluteFill style={{ left: 960, top: 380, width: 860, gap: 28 }}>
      <Headline size={96} delay={20} stagger={24} lines={[{ text: "It's 3:07 AM." }, { text: "Checkout is slow.", dim: true }]} />
      <Sub delay={96} size={36}>Customers can&apos;t pay. You don&apos;t know why.</Sub>
    </AbsoluteFill>
  </Atmosphere>
);

/** 1 · The pain. */
const LOG_LINES: { time: string; level: keyof typeof severity; service: string; text: string }[] = [
  { time: "03:07:02", level: "info", service: "checkout", text: "POST /checkout 200 · 412 ms" },
  { time: "03:07:05", level: "warn", service: "checkout", text: "Slow request POST /checkout · 2,841 ms" },
  { time: "03:07:06", level: "error", service: "payments", text: "Timed out waiting for charge response" },
  { time: "03:07:06", level: "info", service: "checkout", text: "Retrying charge · attempt 2" },
  { time: "03:07:09", level: "warn", service: "checkout", text: "Slow request POST /checkout · 3,102 ms" },
  { time: "03:07:11", level: "error", service: "checkout", text: "Upstream request timed out" },
  { time: "03:07:12", level: "warn", service: "checkout", text: "Slow request POST /checkout · 3,560 ms" },
  { time: "03:07:14", level: "error", service: "payments", text: "Timed out waiting for charge response" },
];

export const Pager: React.FC = () => {
  const frame = useCurrentFrame();

  return (
    <Atmosphere>
      <LeftCopy
        eyebrow="03:08 · the logs"
        lines={[{ text: "Full of errors." }, { text: "None say why.", dim: true }]}
        sub={<>Is it the code? The database?<br />One of the servers?</>}
      />
      <Camera tilt={{ x: [6, 3], y: [-16, -9] }}>
        <div style={{ position: "absolute", right: 70, top: 250 }}>
          <Panel width={960} delay={4} padding={30}>
            <div style={{ display: "flex", flexDirection: "column", gap: 4, fontFamily: mono, fontSize: 23, height: 400, overflow: "hidden" }}>
              {LOG_LINES.map((line, index) => {
                const at = 10 + index * 7;

                return (
                  <div
                    key={index}
                    style={{
                      display: "flex",
                      gap: 22,
                      padding: "9px 12px",
                      borderRadius: 8,
                      backgroundColor: line.level === "error" ? "hsl(354 74% 66% / 0.08)" : "transparent",
                      opacity: tween(frame, [at, at + 5], [0, 1]),
                      translate: `0px ${tween(frame, [at, at + 10], [12, 0])}px`,
                      whiteSpace: "nowrap",
                    }}
                  >
                    <span style={{ color: neutral.mutedForeground }}>{line.time}</span>
                    <span style={{ color: severity[line.level], width: 76, fontWeight: 600 }}>{line.level.toUpperCase()}</span>
                    <span style={{ color: neutral.mutedForeground, width: 124 }}>{line.service}</span>
                    <span style={{ color: neutral.codeForeground }}>{line.text}</span>
                  </div>
                );
              })}
            </div>
          </Panel>
        </div>
      </Camera>
    </Atmosphere>
  );
};

/** A card introducing one pillar of the product, with its illustration. */
const Pillar: React.FC<{ title: string; line: string; delay: number; isNew?: boolean; children: React.ReactNode }> = ({ title, line, delay, isNew, children }) => (
  <Panel width={500} delay={delay} padding={32} sweepAt={delay + 34}>
    <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <div style={{ fontFamily: sans, fontSize: 40, fontWeight: 650, letterSpacing: -1.2, color: neutral.foreground }}>{title}</div>
        {isNew ? (
          <Chip size={20} dot={mark.gold} delay={delay + 16}>
            new
          </Chip>
        ) : null}
      </div>
      <div style={{ fontFamily: sans, fontSize: 25, color: neutral.mutedForeground, lineHeight: 1.35, minHeight: 68 }}>{line}</div>
      <div style={{ height: 190, display: "flex", flexDirection: "column", justifyContent: "center" }}>{children}</div>
    </div>
  </Panel>
);

/** 1½ · Who we are, for a viewer who has never heard of Bilis. */
export const Meet: React.FC = () => {
  const frame = useCurrentFrame();

  return (
    <Atmosphere>
      <AbsoluteFill style={{ alignItems: "center", top: 110, gap: 24 }}>
        <div style={{ display: "flex", alignItems: "center", gap: 30 }}>
          <div style={{ opacity: tween(frame, [0, 12], [0, 1]) }}>
            <BilisMark width={200} delay={0} />
          </div>
          <Headline size={104} delay={4} lines={[{ text: "Meet Bilis." }]} />
        </div>
        <Sub delay={20} size={34}>Self-hostable observability for your apps and servers — every signal in one place.</Sub>
      </AbsoluteFill>
      <AbsoluteFill style={{ top: 400, flexDirection: "row", justifyContent: "center", alignItems: "flex-start", gap: 36 }}>
        <Pillar title="Logs" line="Search every line, live-tail, full text." delay={34}>
          <MiniLogs delay={46} />
        </Pillar>
        <Pillar title="Traces" line="Follow one request across every service." delay={44}>
          <MiniWaterfall delay={56} />
        </Pillar>
        <Pillar title="Metrics" line="Rates, latency, CPU — the numbers behind it." delay={54} isNew>
          <MiniChart delay={66} width={436} />
        </Pillar>
      </AbsoluteFill>
    </Atmosphere>
  );
};

/** 2 · The turn. */
export const Why: React.FC = () => (
  <Atmosphere>
    <Backdrop seed="why" delay={0} opacity={0.5} />
    <AbsoluteFill style={{ justifyContent: "center", alignItems: "center", gap: 30, paddingBottom: 140 }}>
      <Eyebrow delay={0}>Why metrics</Eyebrow>
      <Headline
        align="center"
        size={118}
        delay={2}
        stagger={14}
        lines={[
          { text: "Logs tell you what broke.", dim: true },
          { text: "Metrics tell you why.", accent: { word: "why", color: mark.gold } },
        ]}
      />
    </AbsoluteFill>
  </Atmosphere>
);

/** 3 · See it coming. */
export const Early: React.FC = () => (
  <Atmosphere>
    <LeftCopy
      eyebrow="Latency"
      lines={[{ text: "See it coming," }, { text: "before customers do." }]}
      sub={<>p50, p95 and p99 from the histograms<br />your app already sends.</>}
    />
    <Camera tilt={{ x: [6, 3], y: [-16, -9] }}>
      <div style={{ position: "absolute", right: 70, top: 190 }}>
        <Panel width={940} delay={4} padding={36}>
          <div style={{ display: "flex", flexDirection: "column", gap: 26 }}>
            <Toolbar metric="http.server.request.duration" delay={10} items={[["service", "checkout"]]} />
            <LineChart
              series={[P95]}
              width={866}
              height={470}
              delay={18}
              frames={70}
              yMax={800}
              yFormat={(v) => `${Math.round(v)} ms`}
              xLabels={TIMES}
              markers={[{ index: 34, count: POINTS, label: "p95 ×3 · 03:06", color: chart[0], delay: 84, value: P95.values[34] }]}
            />
          </div>
        </Panel>
      </div>
    </Camera>
  </Atmosphere>
);

/** CPU by host: web-1 calm, web-2 saturating a minute before the latency spike. */
const CPU: Series[] = [
  { label: "web-2", color: chart[2], values: makeSeries("cpu-web2", POINTS, 34, { wobble: 0.1, spike: { at: 33, by: 1.85 } }) },
  { label: "web-1", color: chart[1], values: makeSeries("cpu-web1", POINTS, 31, { wobble: 0.1 }) },
];

/** 4 · Find the cause. */
export const Cause: React.FC = () => (
  <Atmosphere>
    <LeftCopy
      eyebrow="Root cause"
      lines={[{ text: "Your code," }, { text: "or your server?" }]}
      sub={<>Put latency next to CPU.<br />The answer lines up.</>}
    />
    <Camera tilt={{ x: [5, 2], y: [-14, -8] }}>
      <div style={{ position: "absolute", right: 70, top: 110, display: "flex", flexDirection: "column", gap: 22 }}>
        <Panel width={960} delay={4} padding={28}>
          <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
            <Legend series={[P95]} delay={10} />
            <LineChart series={[P95]} width={900} height={250} delay={12} frames={60} yMax={800} yTicks={2} yFormat={(v) => `${Math.round(v)} ms`} markers={[{ index: 34, count: POINTS, label: "03:06", color: chart[0], delay: 96, value: P95.values[34] }]} />
          </div>
        </Panel>
        <Panel width={960} delay={12} padding={28}>
          <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
            <Legend series={CPU.map((line) => ({ ...line, label: `CPU · ${line.label}` }))} delay={18} />
            <LineChart
              series={CPU}
              width={900}
              height={250}
              delay={20}
              frames={60}
              stagger={4}
              yMax={100}
              yTicks={2}
              yFormat={(v) => `${Math.round(v)}%`}
              xLabels={TIMES}
              markers={[{ index: 33, count: POINTS, label: "web-2 · 98%", color: chart[2], delay: 88, value: CPU[0].values[33] }]}
            />
          </div>
        </Panel>
      </div>
    </Camera>
  </Atmosphere>
);

const COMMAND_1 = "curl -fsSL https://bilis.app/install.sh \\";
const COMMAND_2 = "  | sudo BILIS_API_KEY=bilis_•••••••• sh";

/** A terminal that types its command, then prints what the installer prints. */
const TypedTerminal: React.FC<{ delay: number }> = ({ delay }) => {
  const frame = useCurrentFrame();
  const typed = Math.max(0, Math.floor((frame - delay) * 3));
  const line1 = COMMAND_1.slice(0, typed);
  const line2 = COMMAND_2.slice(0, Math.max(0, typed - COMMAND_1.length));
  const done = delay + Math.ceil((COMMAND_1.length + COMMAND_2.length) / 3);
  const cursorOn = Math.floor(frame / 8) % 2 === 0;

  return (
    <div style={{ fontFamily: mono, fontSize: 27, lineHeight: 1.7, color: neutral.codeForeground, whiteSpace: "pre" }}>
      <div>
        <span style={{ color: neutral.mutedForeground }}>$ </span>
        {line1}
        {typed <= COMMAND_1.length && cursorOn ? <span style={{ color: neutral.ring }}>▍</span> : null}
      </div>
      <div style={{ minHeight: 46 }}>
        {line2}
        {typed > COMMAND_1.length && frame < done + 4 && cursorOn ? <span style={{ color: neutral.ring }}>▍</span> : null}
      </div>
      <div style={{ opacity: tween(frame, [done + 8, done + 14], [0, 1]) }}>
        <span style={{ color: severity.debug }}>✓ </span>
        The Bilis agent is running.
      </div>
      <div style={{ color: neutral.mutedForeground, opacity: tween(frame, [done + 14, done + 20], [0, 1]) }}>
        {"  "}host metrics · docker stats · journald logs
      </div>
    </div>
  );
};

const HOSTS = [
  { name: "web-1", cpu: 31, color: chart[1], seed: "h-web1" },
  { name: "web-2", cpu: 98, color: chart[2], seed: "h-web2", hot: true },
  { name: "db-1", cpu: 18, color: chart[0], seed: "h-db1" },
  { name: "worker-1", cpu: 44, color: chart[4], seed: "h-worker1" },
];

const HostCard: React.FC<{ host: (typeof HOSTS)[number]; delay: number }> = ({ host, delay }) => (
  <Panel width={360} delay={delay} padding={26} sweepAt={delay + 30}>
    <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
      <div style={{ display: "flex", justifyContent: "space-between", fontFamily: mono, fontSize: 23, color: neutral.mutedForeground }}>
        <span>{host.name}</span>
        <span>CPU</span>
      </div>
      <Counter to={host.cpu} delay={delay + 4} frames={26} format={(v) => `${Math.round(v)}%`} size={52} color={host.hot ? severity.warn : neutral.foreground} />
      <Sparkline
        values={makeSeries(host.seed, 24, host.cpu, { wobble: 0.18, spike: host.hot ? { at: 20, by: 0.4 } : undefined })}
        color={host.color}
        width={306}
        height={60}
        delay={delay + 8}
      />
    </div>
  </Panel>
);

/** 5 · Every server, one command. */
export const Fleet: React.FC = () => (
  <Atmosphere>
    <AbsoluteFill style={{ left: MARGIN, top: 200, gap: 22 }}>
      <Eyebrow delay={0}>Servers</Eyebrow>
      <Headline size={84} delay={2} stagger={9} lines={[{ text: "Every server." }, { text: "One command.", dim: true }]} />
    </AbsoluteFill>
    <Camera>
      <div style={{ position: "absolute", left: MARGIN, top: 560 }}>
        <Panel width={940} delay={6} padding={40}>
          <TypedTerminal delay={14} />
        </Panel>
      </div>
      <div style={{ position: "absolute", left: 1120, top: 330, display: "grid", gridTemplateColumns: "360px 360px", gap: 24 }}>
        {HOSTS.map((host, index) => (
          <HostCard key={host.name} host={host} delay={62 + index * 6} />
        ))}
      </div>
    </Camera>
  </Atmosphere>
);

/** 6 · The payoff: the answer, handed to you. */
export const Ask: React.FC = () => {
  const frame = useCurrentFrame();
  const answer = ["web-2's CPU hit 98% at 03:06.", "Checkout's p95 followed a minute later."];
  const typed = Math.max(0, Math.floor((frame - 50) * 2.4));
  const first = answer[0].slice(0, typed);
  const second = answer[1].slice(0, Math.max(0, typed - answer[0].length));

  return (
    <Atmosphere>
      <LeftCopy
        eyebrow="MCP"
        lines={[{ text: "Or just" }, { text: "ask your agent." }]}
        sub={<>Claude and other MCP clients<br />read your metrics directly.</>}
      />
      <Camera tilt={{ x: [5, 2], y: [-14, -8] }}>
        <div style={{ position: "absolute", right: 110, top: 300 }}>
          <Panel width={900} delay={4} padding={40}>
            <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
              <div
                style={{
                  alignSelf: "flex-end",
                  padding: "18px 26px",
                  borderRadius: 18,
                  backgroundColor: neutral.accent,
                  fontFamily: sans,
                  fontSize: 30,
                  color: neutral.foreground,
                  opacity: tween(frame, [8, 18], [0, 1]),
                }}
              >
                Why is checkout slow?
              </div>
              <div style={{ display: "flex", flexDirection: "column", alignItems: "flex-start", gap: 10 }}>
                <Chip size={22} delay={22} dot={chart[0]} label="query-metric">
                  http.server.request.duration · p95
                </Chip>
                <Chip size={22} delay={32} dot={chart[2]} label="query-metric">
                  system.cpu.time · by host.name
                </Chip>
              </div>
              <div style={{ fontFamily: sans, fontSize: 32, lineHeight: 1.45, color: neutral.foreground, minHeight: 94 }}>
                {first}
                <br />
                <span style={{ color: neutral.mutedForeground }}>{second}</span>
              </div>
            </div>
          </Panel>
        </div>
      </Camera>
    </Atmosphere>
  );
};

const SDKS = ["Node.js", "Python", "Go", "PHP", "Java", ".NET", "Collector"];

/** 7 · No risk. */
export const Open: React.FC = () => (
  <Atmosphere>
    <Backdrop seed="open-standard" color={chart[1]} opacity={0.3} top={700} />
    <AbsoluteFill style={{ justifyContent: "center", alignItems: "center", gap: 40, paddingBottom: 80 }}>
      <Headline align="center" size={104} delay={2} stagger={10} lines={[{ text: "OpenTelemetry in." }, { text: "No lock-in.", dim: true }]} />
      <div style={{ display: "flex", gap: 16 }}>
        {SDKS.map((sdk, index) => (
          <Chip key={sdk} size={28} delay={26 + index * 3}>
            {sdk}
          </Chip>
        ))}
      </div>
      <Sub delay={48} size={30}>Your existing SDK or Collector · self-host it, or let us run it</Sub>
    </AbsoluteFill>
  </Atmosphere>
);

/** 8 · The ask. */
export const Cta: React.FC = () => {
  const frame = useCurrentFrame();

  return (
    <Atmosphere>
      <Backdrop seed="why" delay={0} opacity={0.35} />
      <AbsoluteFill style={{ justifyContent: "center", alignItems: "center", gap: 34, paddingBottom: 60 }}>
        <Headline align="center" size={150} delay={0} lines={[{ text: "Stop guessing." }]} />
        <div style={{ display: "flex", alignItems: "center", gap: 26, opacity: tween(frame, [22, 34], [0, 1]), translate: `0px ${tween(frame, [22, 40], [16, 0])}px` }}>
          <BilisMark width={170} delay={22} />
          <div style={{ fontFamily: sans, fontSize: 52, fontWeight: 600, color: neutral.foreground, letterSpacing: -1.5 }}>
            Start free at <span style={{ fontFamily: mono, fontWeight: 500 }}>bilis.app</span>
          </div>
        </div>
        <Sub delay={40} size={32}>1,000,000 data points a day, free. Minutes to your first chart.</Sub>
      </AbsoluteFill>
    </Atmosphere>
  );
};
