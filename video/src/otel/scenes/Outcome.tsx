import React from "react";
import { Body, Column, Stage, Title, Waterfall  } from "../../brand";
import type {Span} from "../../brand";

/**
 * The payoff, shown before any of the work.
 *
 * A tutorial that opens with `composer require` is asking for trust it has not
 * earned yet. This is the thing the next two minutes buys.
 */
const SPANS: Span[] = [
  { name: "GET /checkout", service: "web", start: 0, width: 1, depth: 0, ms: 412 },
  { name: "app bootstrap", service: "web", start: 0.02, width: 0.09, depth: 1, ms: 38 },
  { name: "SELECT users", service: "web", start: 0.12, width: 0.06, depth: 1, ms: 24 },
  { name: "POST /payments", service: "payments", start: 0.2, width: 0.52, depth: 1, ms: 214 },
  { name: "SELECT orders", service: "payments", start: 0.24, width: 0.11, depth: 2, ms: 45 },
  { name: "charge card", service: "payments", start: 0.38, width: 0.32, depth: 2, ms: 132 },
  { name: "INSERT receipts", service: "web", start: 0.74, width: 0.19, depth: 1, ms: 78, failed: true },
];

export const Outcome: React.FC = () => (
  <Stage chapter="What you get">
    <Column gap={40}>
      <Title delay={0}>One request, every span.</Title>

      <Waterfall spans={SPANS} delay={4} />

      <Body delay={54}>
        Bars are coloured by service; a failed span overrides to red.
      </Body>
    </Column>
  </Stage>
);
