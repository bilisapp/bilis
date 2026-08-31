import React from "react";
import { Body, Callout, CodeBlock, Column, Stage, Strong, Title } from "../../brand";

/**
 * The scene that makes this survive real traffic.
 *
 * Sampling is the thing every tutorial leaves out and every production install
 * needs on day two, so it is a step rather than a footnote.
 */
export const Production: React.FC = () => (
  <Stage chapter="Ready it for production" step="05">
    <Column gap={32}>
      <Title delay={0}>Keep every trace that matters.</Title>

      <Body delay={12}>
        Sample healthy traffic — keep{" "}
        <Strong>every error and every slow trace</Strong>.
      </Body>

      <CodeBlock
        delay={26}
        lang="env"
        title=".env"
        stagger={9}
        code={`OTEL_TRACES_SAMPLER_TYPE=traceidratio
OTEL_TRACES_SAMPLER_TRACEIDRATIO_RATIO=0.1
OTEL_TRACES_TAIL_SAMPLING_ENABLED=true`}
      />

      <Callout delay={62} tone="note" label="Octane, Horizon and queue workers">
        Detected automatically. Spans are batched and flushed on the worker's
        own schedule.
      </Callout>
    </Column>
  </Stage>
);
