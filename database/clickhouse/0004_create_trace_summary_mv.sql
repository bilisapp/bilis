-- Maintains trace_summary from every otel_traces insert. SCHEMA.md R11.
CREATE MATERIALIZED VIEW IF NOT EXISTS trace_summary_mv TO trace_summary AS
SELECT
    ProjectId,
    TraceId,
    min(Timestamp)                AS Start,
    -- Timestamp is a span's START, so `max(Timestamp)` would be the last span's
    -- start rather than the trace's end -- understating every trace's duration
    -- by however long its final span ran. Measured on seeded data before this
    -- was fixed: a 4.29s trace reported 168ms. Duration is nanoseconds, and
    -- toIntervalNanosecond keeps the result DateTime64(9, 'UTC').
    max(Timestamp + toIntervalNanosecond(Duration)) AS End,
    count()                       AS SpanCount,
    -- 'Error' is what the exporter writes: pdata's StatusCode.String() returns
    -- Unset / Ok / Error, NOT the proto enum name STATUS_CODE_ERROR. Bilis's own
    -- mapper normalises to the same forms (R10). A mismatch here counts zero
    -- errors forever, without an error of its own.
    countIf(StatusCode = 'Error') AS ErrorCount,
    -- max(if(...)), never anyIf(...): a block carrying no root span contributes
    -- '', and `any` may pick that empty string during a merge, so the root
    -- operation would vanish at random. max always prefers the real value.
    max(if(ParentSpanId = '', SpanName, ''))    AS RootName,
    max(if(ParentSpanId = '', ServiceName, '')) AS RootService
FROM otel_traces
WHERE TraceId != ''
GROUP BY ProjectId, TraceId
