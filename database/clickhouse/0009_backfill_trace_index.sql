-- Backfills trace_index from trace_summary on a deployed database. A fresh
-- install has both tables empty and this is a no-op. SCHEMA.md R13.
--
-- From trace_summary, not otel_traces: the summary keeps 90 days against the
-- spans' 30, and the list reads the summary, so every trace the list may show
-- has to be reachable through the index. Grouped first, because trace_summary
-- holds one row per trace per insert block (R11).
--
-- This file re-runs on every boot, so it decides for itself whether it still
-- has work to do: it runs while the index is empty, or while the index's
-- earliest hour is later than the summary's earliest trace. "Is the index
-- empty" alone is not a good enough test -- during a rolling deploy an old
-- container keeps ingesting, its inserts feed the new view (0008) the moment
-- it exists, and an emptiness guard would then skip the whole history without
-- a word; but those fresh rows all sit at the top of the index, so its
-- min(Hour) gives the race away. Two column mins are a vectorised scan of
-- eight bytes a row; a distinct count of every (ProjectId, TraceId) pair would
-- hash the whole history into memory at every start. It self-heals: a refill
-- only ever adds rows that share their key with existing ones and merge away.
--
-- The summary side ignores its last day of retention. Expiry is lazy on both
-- tables and not in step -- the summary deletes rows at merge time, the index
-- drops whole daily parts -- so without the bound a trace the index has
-- already dropped could keep this statement firing until the summary's own
-- TTL merge caught up.
--
-- The aliases deliberately differ from the column names and the INSERT maps
-- them by position: `min(Start) AS Start` shadows the source column and
-- ClickHouse raises ILLEGAL_AGGREGATION (the R11 alias trap, again).
INSERT INTO trace_index (ProjectId, Hour, TraceId, Start, End)
SELECT ProjectId,
       toStartOfHour(min(Start)) AS IndexHour,
       TraceId,
       min(Start)                AS FirstStart,
       max(End)                  AS LastEnd
FROM trace_summary
WHERE Start >= now() - toIntervalDay(90)
  AND (
    -- Cheap guard, evaluated on every boot: an index that is empty, or whose
    -- earliest hour is later than the summary's earliest trace, has not been
    -- backfilled. Two column mins, never a distinct count of every trace pair
    -- (uniqExact would hash the whole history into memory at each start).
    -- The summary side is bounded to 89 days because expiry is lazy on both
    -- tables and not in step: the index drops whole day partitions at 90-91
    -- days, so in steady state its min(Hour) sits below any summary trace in
    -- the last 89 days and the guard stays false.
    (SELECT count() FROM trace_index) = 0
        OR (SELECT min(Hour) FROM trace_index)
        > (SELECT toStartOfHour(min(Start)) FROM trace_summary WHERE Start >= now() - toIntervalDay(89))
    )
GROUP BY ProjectId, TraceId
