import React from "react";
import { Body, Column, Stage, Terminal, Title } from "../../brand";

export const OneCommand: React.FC = () => (
  <Stage chapter="Step 1" step="01">
    <Column gap={40} justify="center">
      <Title delay={0}>One package.</Title>

      <Terminal
        delay={10}
        stagger={16}
        fontSize={25}
        lines={[
          { kind: "prompt", text: "composer require" },
          { kind: "cont", text: "  keepsuit/laravel-opentelemetry" },
          { kind: "ok", text: "  Installing (2.2.4)" },
        ]}
      />

      <Body delay={62}>No PHP extension. No Collector to run.</Body>
    </Column>
  </Stage>
);
