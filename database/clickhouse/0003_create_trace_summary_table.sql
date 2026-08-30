-- One row per trace, maintained by trace_summary_mv (0004). Powers the trace
-- list and narrows a bare-TraceId lookup to a time range before otel_traces is
-- touched at all. Governed by SCHEMA.md R11 -- every choice below fails silently
-- if changed, so read the rule before editing this file.
CREATE TABLE IF NOT EXISTS trace_summary
(
    ProjectId   LowCardinality(String),
    TraceId     String                                          CODEC(ZSTD(1)),
    Start       SimpleAggregateFunction(min, DateTime64(9, 'UTC')),
    End         SimpleAggregateFunction(max, DateTime64(9, 'UTC')),
    SpanCount   SimpleAggregateFunction(sum, UInt64),
    ErrorCount  SimpleAggregateFunction(sum, UInt64),
    RootName    SimpleAggregateFunction(max, String),
    RootService SimpleAggregateFunction(max, String)
)
-- AggregatingMergeTree, never ReplacingMergeTree: the materialized view fires
-- once per insert block, so one trace whose spans arrive in several blocks emits
-- several rows here. Replacing would keep only the last and corrupt Start/End.
-- The corollary binds readers too: rows collapse only when parts merge, so every
-- query re-aggregates with GROUP BY (ProjectId, TraceId) rather than assuming
-- one row per trace.
--
-- No PARTITION BY on purpose. Start is an aggregate, and a merge can lower it
-- across midnight; ClickHouse will not move a row between partitions, so a
-- partitioned table would silently hold rows in the wrong one. The cost is
-- ttl_only_drop_parts, which is affordable at one row per trace.
ENGINE = AggregatingMergeTree
ORDER BY (ProjectId, TraceId)
-- Summaries deliberately outlive their spans (90 days here, 30 in otel_traces):
-- they are cheap, and they keep trend data after the detail is gone. The UI must
-- render a summary whose spans have expired, with the waterfall link disabled.
TTL toDateTime(Start) + toIntervalDay(90)
SETTINGS index_granularity = 8192
