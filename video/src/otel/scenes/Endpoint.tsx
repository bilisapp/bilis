import React from "react";
import { Callout, CodeBlock, Column, Stage, Strong, Title } from "../../brand";

export const Endpoint: React.FC = () => (
  <Stage chapter="Point it at Bilis" step="02">
    <Column gap={36}>
      <Title delay={0}>Four lines in your .env</Title>

      <CodeBlock
        delay={12}
        lang="env"
        title=".env"
        stagger={9}
        code={`OTEL_SERVICE_NAME=checkout
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api
OTEL_EXPORTER_OTLP_HEADERS=x-bilis-key=bilis_your_key_here`}
      />

      <Callout delay={64} tone="note" label="Two details worth getting right">
        The endpoint ends in <Strong>/api</Strong> — the SDK appends
        /v1/traces itself. And <Strong>x-bilis-key</Strong> carries your key
        with no space in it, so it needs no url-encoding in any SDK.
      </Callout>
    </Column>
  </Stage>
);
