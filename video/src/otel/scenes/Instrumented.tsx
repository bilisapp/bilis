import React from "react";
import { Body, Column, Grid, Stage, Strong, Title  } from "../../brand";
import type {GridItem} from "../../brand";

/**
 * The value scene: everything that becomes a span without writing any code.
 *
 * This is what the previous two steps actually bought, so it comes straight
 * after configuration and before anything optional.
 */
const INSTRUMENTED: GridItem[] = [
  { label: "Requests", detail: "route, status, duration" },
  { label: "Database", detail: "every Eloquent query" },
  { label: "Queue jobs", detail: "linked to the request" },
  { label: "Cache", detail: "hits and misses" },
  { label: "Outbound HTTP", detail: "Guzzle and Http::" },
  { label: "Redis", detail: "commands and timing" },
  { label: "Views", detail: "renders and Livewire" },
  { label: "Console", detail: "the commands you name" },
];

export const Instrumented: React.FC = () => (
  <Stage chapter="What you get for free" step="03">
    <Column gap={34}>
      <Title delay={0}>No code changes. None.</Title>

      <Body delay={12}>
        Everything below becomes a span on its own, <Strong>nested under the
        request that caused it</Strong>.
      </Body>

      <Grid items={INSTRUMENTED} columns={4} delay={26} stagger={5} />
    </Column>
  </Stage>
);
