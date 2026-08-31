import React from "react";
import { Body, CodeBlock, Column, Stage, Strong, Title } from "../../brand";

/**
 * The .env, broken across lines by hand.
 *
 * A portrait frame is 1080 wide and these keys are long, so the value goes on
 * its own indented line rather than shrinking the type until nobody on a phone
 * can read it. It stays valid — dotenv does not care, and legibility is the
 * whole point of the format.
 */
export const FourLines: React.FC = () => (
  <Stage chapter="Step 2" step="02">
    <Column gap={38} justify="center">
      <Title delay={0}>Four lines of .env</Title>

      <CodeBlock
        delay={10}
        lang="env"
        title=".env"
        stagger={7}
        code={`OTEL_SERVICE_NAME=checkout

OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf

OTEL_EXPORTER_OTLP_ENDPOINT=
    https://your-bilis-host/api

OTEL_EXPORTER_OTLP_HEADERS=
    x-bilis-key=bilis_your_key`}
      />

      <Body delay={78}>
        The endpoint ends in <Strong>/api</Strong> — the SDK adds the rest.
      </Body>
    </Column>
  </Stage>
);
