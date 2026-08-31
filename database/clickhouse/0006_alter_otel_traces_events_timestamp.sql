-- Converges a deployed otel_traces onto the Events.Timestamp type 0002 now
-- declares: DateTime64(9, 'UTC') instead of the naive DateTime64(9) that
-- shipped first.
--
-- Why it matters: session_timezone=UTC (pinned on every request by
-- ClickHouseClient) governs how a timestamp string is PARSED, but not how a
-- naive DateTime64 column is RENDERED -- that follows the server timezone
-- (verified on 26.9, see .ai/rules/click-house.md). The span Timestamp was
-- already declared 'UTC'; the event ticks inside it were not, so on a
-- non-UTC host every event rendered offset from the span it belongs to and
-- the UI, which appends `Z`, placed it wrong.
--
-- Metadata only: DateTime64 stores an epoch either way, so no data is
-- rewritten and the statement is instant. A fresh database already has the
-- right type from 0002 and this is a no-op there; on a deployed one it is a
-- no-op from the second run on. IF EXISTS keeps it safe under
-- docker-entrypoint.sh, which runs clickhouse:migrate once per container role.
ALTER TABLE otel_traces
    MODIFY COLUMN IF EXISTS `Events.Timestamp` Array(DateTime64(9, 'UTC'))
