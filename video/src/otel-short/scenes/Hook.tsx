import React from "react";
import { AbsoluteFill } from "remotion";
import {
  BilisMark,
  Column,
  Display,
  Stage,
  Waterfall,
  useFormat
  
} from "../../brand";
import type {Span} from "../../brand";

/**
 * The first two seconds, which are the only ones a Short is guaranteed.
 *
 * The claim and the proof land together: the sentence is on screen at frame 0
 * and the waterfall starts drawing immediately under it. No logo-first opening
 * — a Short that spends its first second on a mark has spent the whole budget.
 */
const SPANS: Span[] = [
  { name: "GET /checkout", service: "web", start: 0, width: 1, depth: 0, ms: 412 },
  { name: "SELECT users", service: "web", start: 0.08, width: 0.1, depth: 1, ms: 24 },
  { name: "POST /payments", service: "payments", start: 0.2, width: 0.52, depth: 1, ms: 214 },
  { name: "charge card", service: "payments", start: 0.38, width: 0.32, depth: 2, ms: 132 },
  { name: "INSERT receipts", service: "web", start: 0.74, width: 0.19, depth: 1, ms: 78, failed: true },
];

export const Hook: React.FC = () => {
  const format = useFormat();

  return (
    <Stage>
      <Column gap={44} justify="center">
        <Display delay={0}>Trace every Laravel request.</Display>

        <Waterfall
          spans={SPANS}
          delay={10}
          stagger={6}
          labelWidth={330}
          rowHeight={62}
          showDurations={false}
        />
      </Column>

      <AbsoluteFill
        name="Mark"
        style={{
          justifyContent: "flex-end",
          alignItems: "center",
          paddingBottom: format.safeBottom - 110,
        }}
      >
        <BilisMark width={230} delay={40} />
      </AbsoluteFill>
    </Stage>
  );
};
