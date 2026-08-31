import React from "react";
import { Body, CodeBlock, Column, Stage, Strong, Title } from "../../brand";

/**
 * Which signal travels which way.
 *
 * Framed as a routing decision, which is what it is: traces go over OTLP, logs
 * go through the channel that can carry Laravel's context, and metrics are
 * simply not part of this product.
 */
export const Signals: React.FC = () => (
  <Stage chapter="Choose your signals" step="04">
    <Column gap={28}>
      <Title delay={0}>Traces over OTLP, logs over Monolog.</Title>

      <Body delay={12}>
        Send spans over OTLP; let Laravel&apos;s own log channel carry the lines,
        with their <Strong>context and level intact</Strong>.
      </Body>

      <CodeBlock
        delay={26}
        lang="env"
        title=".env"
        stagger={10}
        code={`OTEL_METRICS_EXPORTER=null
OTEL_LOGS_EXPORTER=null`}
      />

      <CodeBlock
        delay={52}
        lang="env"
        title=".env — the Bilis log channel"
        stagger={9}
        fontSize={29}
        code={`LOG_STACK=single,bilis
BILIS_ENDPOINT=https://your-bilis-host
BILIS_API_KEY=bilis_your_key_here`}
      />
    </Column>
  </Stage>
);
