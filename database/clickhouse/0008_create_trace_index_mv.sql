-- Maintains trace_index from every otel_traces insert. SCHEMA.md R13.
--
-- Fires once per insert block, like trace_summary_mv, and groups the block the
-- same way: one row per (ProjectId, TraceId) it contains, filed under the hour
-- of that block's earliest span. End is the last span's END (Timestamp plus
-- Duration), not its start, for the reason 0004 gives -- and because the tail
-- asks "which traces have a block that ends after my cursor".
CREATE
MATERIALIZED VIEW IF NOT EXISTS trace_index_mv TO trace_index AS
SELECT ProjectId,
       toStartOfHour(min(Timestamp)) AS Hour,
    TraceId,
    min(Timestamp)                AS
Start
    , max (Timestamp + toIntervalNanosecond(Duration)) AS
End
FROM otel_traces
WHERE TraceId != ''
GROUP BY ProjectId, TraceId
