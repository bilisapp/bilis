import React from "react";
import { Column, Grid, Stage, Title  } from "../../brand";
import type {GridItem} from "../../brand";

const INSTRUMENTED: GridItem[] = [
  { label: "Requests", detail: "route + status" },
  { label: "Database", detail: "every query" },
  { label: "Queue jobs", detail: "linked to it" },
  { label: "Cache", detail: "hits, misses" },
  { label: "Outbound HTTP", detail: "Guzzle, Http::" },
  { label: "Redis", detail: "and Livewire" },
];

export const Free: React.FC = () => (
  <Stage chapter="Step 3" step="03">
    <Column gap={38} justify="center">
      <Title delay={0}>That&apos;s it. No code changes.</Title>

      <Grid items={INSTRUMENTED} columns={2} delay={12} stagger={6} />
    </Column>
  </Stage>
);
