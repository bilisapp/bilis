-- The trace list's time index. One row per trace per hour it was first seen
-- in, maintained by trace_index_mv (0008) from every otel_traces insert.
-- SCHEMA.md R13.
--
-- Why a second table: trace_summary is keyed (ProjectId, TraceId) so that a
-- pasted id is a point lookup, and that key is useless for "the newest traces
-- in this window" -- TraceId is random, so a Start range prunes nothing and
-- every page load and every five-second poll scanned a project's whole 90-day
-- summary (measured: Granules N/N, only ProjectId used). This table is keyed by
-- the hour instead. The list finds candidate ids here inside the window, then
-- re-aggregates exactly those from trace_summary by its own key.
--
-- It is an index, not a second summary: it carries no counts and no root, and
-- readers never answer from it alone. What it holds per row is a block's first
-- span start (Start) and last span end (End) for the hour that block's Start
-- fell in, so the list can ask "which traces have a block starting in this
-- window" and the tail can ask "which traces have a block that ends after my
-- cursor" -- the latter is what lets a root span that arrives last (a
-- minutes-long session, a queue job) re-send its trace with full counts.
CREATE TABLE IF NOT EXISTS trace_index
(
    ProjectId
    LowCardinality
(
    String
),
    -- toStartOfHour of the block's earliest span. Part of the key, so it is
    -- fixed for the life of the row and a merge cannot move it -- which is what
    -- makes PARTITION BY safe here when it is not on trace_summary.
    Hour DateTime
(
    'UTC'
),
    TraceId String CODEC
(
    ZSTD
(
    1
)),
    Start SimpleAggregateFunction
(
    min,
    DateTime64
(
    9,
    'UTC'
)),
    End SimpleAggregateFunction
(
    max,
    DateTime64
(
    9,
    'UTC'
))
    )
    -- AggregatingMergeTree so the rows a trace's several insert blocks write for
-- the same hour collapse into one on merge. A trace whose blocks fall in
-- different hours keeps one row per hour; that is deliberate -- each row is a
-- true statement about a block -- and harmless, because the list only uses
-- this table to nominate ids and decides membership on trace_summary's
-- min(Start).
    ENGINE = AggregatingMergeTree
    PARTITION BY toDate
(
    Hour
)
    ORDER BY
(
    ProjectId,
    Hour,
    TraceId
)
    -- Retention matches trace_summary, so a candidate this table nominates is
-- still there to aggregate. Daily partitions make expiry a drop, not a rewrite.
    TTL Hour + toIntervalDay
(
    90
)
    SETTINGS index_granularity = 8192,
    ttl_only_drop_parts = 1
