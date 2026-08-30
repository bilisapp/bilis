-- Migrates a deployed otel_logs from the ClickHouse < 26.2 body index
-- (tokenbf_v1) to the >= 26.2 one (text). SCHEMA.md R5.
--
-- A fresh database never needs this: 0001 already creates the text index, and
-- both clauses below are then no-ops. A deployed one gets the swap here, because
-- CREATE TABLE IF NOT EXISTS cannot alter a table that already exists.
--
-- Idempotent by IF EXISTS / IF NOT EXISTS, which matters twice over: this file
-- is re-applied on every clickhouse:migrate, and docker-entrypoint.sh runs that
-- command once per container role, so a full deploy can issue it three times
-- concurrently.
--
-- This is metadata only and takes effect immediately for NEW parts. Existing
-- parts keep answering body searches by full scan -- correct, just unaccelerated
-- -- until an operator runs `php artisan clickhouse:materialize-index`, which is
-- deliberately not part of migrate: rebuilding index files across every existing
-- part is real I/O and must not repeat on every boot.
ALTER TABLE otel_logs
    DROP INDEX IF EXISTS idx_lower_body,
    ADD INDEX IF NOT EXISTS idx_lower_body lower(Body) TYPE text(tokenizer = 'splitByNonAlpha') GRANULARITY 8
