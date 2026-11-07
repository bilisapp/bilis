-- Issues: one thin row per exception identity per hour it occurred in.
--
-- issue_summary answers "this issue, all time" by key, but "which issues
-- happened in the last 24 hours" would be a full scan of a project's 90 days
-- there (the fingerprint is a hash; a LastSeen range prunes nothing). This
-- table is keyed by the hour, so the list nominates its fingerprints here —
-- with exact per-window event and user counts and a per-hour series for the
-- sparkline and histogram — and reads headlines from issue_summary (R18).
--
-- Hour is part of the key and fixed for the life of the row, which is what
-- makes daily partitions and a partition-drop TTL safe here.
CREATE TABLE IF NOT EXISTS issue_hourly
(
    ProjectId        LowCardinality(String),
    Hour             DateTime('UTC'),
    IssueFingerprint String                                CODEC(ZSTD(1)),
    Events           SimpleAggregateFunction(sum, UInt64),
    Users            AggregateFunction(uniq, String)
)
ENGINE = AggregatingMergeTree
PARTITION BY toDate(Hour)
ORDER BY (ProjectId, Hour, IssueFingerprint)
TTL Hour + toIntervalDay(30)
SETTINGS index_granularity = 8192, ttl_only_drop_parts = 1
