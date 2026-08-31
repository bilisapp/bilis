import React from "react";
import { Body, Column, Stage, Terminal, Title } from "../../brand";

export const Install: React.FC = () => (
  <Stage chapter="Install the package" step="01">
    <Column gap={40}>
      <Title delay={0}>
        <code style={{ fontSize: "0.86em" }}>keepsuit/laravel-opentelemetry</code>
      </Title>

      <Body delay={12}>
        Chosen over the official auto-instrumentation because it needs no{" "}
        <code>ext-opentelemetry</code> — and because it knows about Octane,
        Horizon and queue workers, which decides whether spans in a long-running
        process ever get flushed.
      </Body>

      <Terminal
        delay={30}
        stagger={14}
        lines={[
          { kind: "prompt", text: "composer require keepsuit/laravel-opentelemetry" },
          { kind: "ok", text: "  - Installing keepsuit/laravel-opentelemetry (2.2.4)" },
          { kind: "prompt", text: "php artisan vendor:publish --tag=opentelemetry-config" },
          { kind: "ok", text: "  INFO  Publishing [opentelemetry-config] assets." },
        ]}
      />
    </Column>
  </Stage>
);
