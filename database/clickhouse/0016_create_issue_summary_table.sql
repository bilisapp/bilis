-- Issues: one aggregate row per exception identity, all time (90 days).
--
-- Keyed (ProjectId, IssueFingerprint), so an issue page is a point lookup.
-- AggregatingMergeTree, like trace_summary: every insert block that carries
-- an issue's events adds a partial row, merges fold them eventually and never
-- on demand, so EVERY read re-aggregates with GROUP BY ProjectId,
-- IssueFingerprint and the -Merge / outer aggregate of each column (R17).
--
-- The headline columns are argMax states keyed on the event time, never
-- `any`/`anyLast`: those pick whichever partial row a merge happens to read
-- first, so the title of an issue would flip between deploys at random.
-- Releases use the -If combinator (same state type) so an event without a
-- release does not blank the last known one.
--
-- No TTL, on purpose. TTL is evaluated per stored row, and an issue's rows are
-- partial until merges fold them — which may never happen for a large old
-- part. A TTL on LastSeen would delete the oldest partial row of an issue
-- that is still recurring, taking its true FirstSeen, FirstRelease and part
-- of its event count with it. One row per (project, fingerprint) is small;
-- dead issues simply stop appearing in any window (the list reads
-- issue_hourly, which does expire). Events expire after 30 days in otel_logs,
-- so the UI must handle an issue whose events are gone.
--
-- LastIngested is when ClickHouse last received an event of the issue (the
-- view's now(), evaluated as the block is written), not when the client says
-- it happened. "Regressed" compares it with the resolution time, so a client
-- with a fast clock cannot regress an issue at once, and a buffered SDK that
-- flushes old events after the resolution still does.
CREATE TABLE IF NOT EXISTS issue_summary
(
    ProjectId        LowCardinality(String),
    IssueFingerprint String                                                   CODEC(ZSTD(1)),
    FirstSeen        SimpleAggregateFunction(min, DateTime64(9, 'UTC')),
    LastSeen         SimpleAggregateFunction(max, DateTime64(9, 'UTC')),
    Events           SimpleAggregateFunction(sum, UInt64),
    LastIngested     SimpleAggregateFunction(max, DateTime('UTC')),
    Users            AggregateFunction(uniq, String),
    Services         SimpleAggregateFunction(groupUniqArrayArray, Array(String)),
    Environments     SimpleAggregateFunction(groupUniqArrayArray, Array(String)),
    ExceptionType    AggregateFunction(argMax, String, DateTime64(9, 'UTC')),
    Message          AggregateFunction(argMax, String, DateTime64(9, 'UTC')),
    Origin           AggregateFunction(argMax, String, DateTime64(9, 'UTC')),
    LastService      AggregateFunction(argMax, String, DateTime64(9, 'UTC')),
    LastRelease      AggregateFunction(argMax, String, DateTime64(9, 'UTC')),
    FirstRelease     AggregateFunction(argMin, String, DateTime64(9, 'UTC'))
)
ENGINE = AggregatingMergeTree
ORDER BY (ProjectId, IssueFingerprint)
SETTINGS index_granularity = 8192
