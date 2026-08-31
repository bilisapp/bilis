import React from "react";
import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from "remotion";
import {
  BilisMark,
  CodeBlock,
  Grid,
  PunchIn,
  StepChip,
  Terminal,
  Waterfall,
  WordPop,
  mark,
  neutral,
  sans,
  severity,
  useFormat
  
  
} from "../../brand";
import type {GridItem, Span} from "../../brand";

/**
 * The eight beats of the high-energy cut, in one file.
 *
 * They live together because none of them is reusable — each is two or three
 * seconds of one idea, and splitting them across eight files would hide the
 * pacing, which is the only thing that matters here.
 */

/** Full-bleed ground with a slow drift, so no frame is ever perfectly still. */
const Ground: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const frame = useCurrentFrame();

  return (
    <AbsoluteFill style={{ backgroundColor: neutral.background }}>
      <AbsoluteFill
        style={{
          backgroundImage: `linear-gradient(${neutral.card} 1px, transparent 1px), linear-gradient(90deg, ${neutral.card} 1px, transparent 1px)`,
          backgroundSize: "70px 70px",
          opacity: 0.5,
          translate: `0px ${interpolate(frame, [0, 90], [0, -70])}px`,
        }}
      />
      <PunchIn>{children}</PunchIn>
    </AbsoluteFill>
  );
};

const Centre: React.FC<{ children: React.ReactNode; gap?: number }> = ({
  children,
  gap = 40,
}) => {
  const format = useFormat();

  return (
    <AbsoluteFill
      style={{
        justifyContent: "center",
        alignItems: "center",
        gap,
        paddingLeft: format.margin,
        paddingRight: format.margin,
        paddingBottom: format.safeBottom - 180,
      }}
    >
      {children}
    </AbsoluteFill>
  );
};

export const BlackBox: React.FC = () => (
  <Ground>
    <Centre>
      <WordPop text="Your Laravel app" delay={0} size={120} />
      <WordPop
        text="is a black box."
        delay={14}
        size={120}
        accent={["black", "box"]}
      />
    </Centre>
  </Ground>
);

const SPANS: Span[] = [
  { name: "GET /checkout", service: "web", start: 0, width: 1, depth: 0, ms: 412 },
  { name: "SELECT users", service: "web", start: 0.08, width: 0.1, depth: 1, ms: 24 },
  { name: "POST /payments", service: "payments", start: 0.2, width: 0.52, depth: 1, ms: 214 },
  { name: "charge card", service: "payments", start: 0.38, width: 0.32, depth: 2, ms: 132 },
  { name: "INSERT receipts", service: "web", start: 0.74, width: 0.19, depth: 1, ms: 78, failed: true },
];

export const NotAnymore: React.FC = () => (
  <Ground>
    <Centre gap={34}>
      <WordPop text="Not anymore." delay={0} size={132} accent={["anymore"]} />
      <Waterfall
        spans={SPANS}
        delay={6}
        stagger={3}
        labelWidth={320}
        rowHeight={58}
        showDurations={false}
      />
    </Centre>
  </Ground>
);

export const EveryQuery: React.FC = () => {
  const frame = useCurrentFrame();

  return (
    <Ground>
      <Centre gap={30}>
        <WordPop text="Every request." delay={0} size={104} />
        <WordPop text="Every query." delay={10} size={104} />
        <WordPop text="Every job." delay={20} size={104} />
        <div
          style={{
            fontFamily: sans,
            fontSize: 52,
            fontWeight: 600,
            color: severity.debug,
            opacity: interpolate(frame, [34, 44], [0, 1], {
              extrapolateLeft: "clamp",
              extrapolateRight: "clamp",
              easing: Easing.bezier(0.16, 1, 0.3, 1),
            }),
          }}
        >
          on your own server
        </div>
      </Centre>
    </Ground>
  );
};

export const ThreeSteps: React.FC = () => {
  const frame = useCurrentFrame();

  return (
    <Ground>
      <Centre gap={10}>
        <div
          style={{
            fontFamily: sans,
            fontSize: 340,
            fontWeight: 700,
            letterSpacing: -18,
            lineHeight: 1,
            color: mark.gold,
            scale: interpolate(frame, [0, 14], [0.4, 1], {
              extrapolateLeft: "clamp",
              extrapolateRight: "clamp",
              easing: Easing.bezier(0.16, 1, 0.3, 1),
              output: "perceptual-scale",
            }),
          }}
        >
          3
        </div>
        <WordPop text="steps. that's it." delay={10} size={92} />
      </Centre>
    </Ground>
  );
};

export const StepOne: React.FC = () => (
  <Ground>
    <Centre gap={34}>
      <StepChip label="STEP 1" delay={0} />
      <WordPop text="Install one package" delay={4} size={82} />
      <Terminal
        delay={14}
        stagger={9}
        fontSize={25}
        lines={[
          { kind: "prompt", text: "composer require" },
          { kind: "cont", text: "  keepsuit/laravel-opentelemetry" },
          { kind: "ok", text: "  Installing (2.2.4)" },
        ]}
      />
    </Centre>
  </Ground>
);

export const StepTwo: React.FC = () => (
  <Ground>
    <Centre gap={30}>
      <StepChip label="STEP 2" delay={0} />
      <WordPop text="Paste 4 lines" delay={4} size={82} accent={["4"]} />
      <CodeBlock
        delay={14}
        lang="env"
        title=".env"
        stagger={5}
        code={`OTEL_SERVICE_NAME=checkout

OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf

OTEL_EXPORTER_OTLP_ENDPOINT=
    https://your-bilis-host/api

OTEL_EXPORTER_OTLP_HEADERS=
    x-bilis-key=bilis_your_key`}
      />
    </Centre>
  </Ground>
);

const FREE: GridItem[] = [
  { label: "Requests", detail: "route + status" },
  { label: "Database", detail: "every query" },
  { label: "Queue jobs", detail: "linked to it" },
  { label: "Cache", detail: "hits, misses" },
  { label: "HTTP client", detail: "Guzzle, Http::" },
  { label: "Redis", detail: "and Livewire" },
];

export const StepThree: React.FC = () => (
  <Ground>
    <Centre gap={28}>
      <StepChip label="STEP 3" delay={0} />
      <WordPop text="There is no step 3." delay={4} size={82} accent={["no"]} />
      <Grid items={FREE} columns={2} delay={16} stagger={3} />
    </Centre>
  </Ground>
);

export const Loop: React.FC = () => {
  const frame = useCurrentFrame();

  return (
    <Ground>
      <Centre gap={36}>
        <BilisMark width={380} still />
        <WordPop text="Bilis" delay={2} size={150} />
        <div
          style={{
            fontFamily: sans,
            fontSize: 50,
            color: neutral.mutedForeground,
            opacity: interpolate(frame, [16, 28], [0, 1], {
              extrapolateLeft: "clamp",
              extrapolateRight: "clamp",
            }),
          }}
        >
          bilis.app
        </div>
      </Centre>
    </Ground>
  );
};
