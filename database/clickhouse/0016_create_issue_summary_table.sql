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
-- TTL is on LastSeen: an issue quiet for 90 days ages out, one that keeps
-- recurring stays. Its events expire after 30 (otel_logs), so the UI must
-- handle an issue whose events are gone, as the trace list does.
CREATE TABLE IF NOT EXISTS issue_summary
(
    ProjectId        LowCardinality(String),
    IssueFingerprint String                                                   CODEC(ZSTD(1)),
    FirstSeen        SimpleAggregateFunction(min, DateTime64(9, 'UTC')),
    LastSeen         SimpleAggregateFunction(max, DateTime64(9, 'UTC')),
    Events           SimpleAggregateFunction(sum, UInt64),
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
TTL toDateTime(LastSeen) + toIntervalDay(90)
SETTINGS index_granularity = 8192
