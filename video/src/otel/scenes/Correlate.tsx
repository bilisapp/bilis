import React from "react";
import { Body, CodeBlock, Column, Stage, Strong, Title } from "../../brand";

export const Correlate: React.FC = () => (
  <Stage chapter="Link logs to spans" step="06">
    <Column gap={34}>
      <Title delay={0}>Jump from a log line to its trace</Title>

      <Body delay={12}>
        One Monolog tap stamps the <Strong>trace and span id</Strong> onto every
        line, and the two signals link both ways.
      </Body>

      <CodeBlock
        delay={26}
        lang="php"
        title="app/Logging/AddTraceContext.php"
        stagger={7}
        fontSize={28}
        code={`$context = Span::getCurrent()->getContext();
// Only stamp real ids: outside a span they are all zeroes.
if (! $context->isValid()) {
    return $record;
}
return $record->with(extra: [
    'trace_id' => $context->getTraceId(),
    'span_id' => $context->getSpanId(),
]);`}
      />
    </Column>
  </Stage>
);
