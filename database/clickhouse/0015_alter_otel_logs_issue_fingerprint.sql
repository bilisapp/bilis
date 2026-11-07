-- Issues: the exception identity column on otel_logs.
--
-- Schema files are append-only once applied, so 0001 is left as it shipped and
-- every database — fresh or deployed — gets the column from this file. Both
-- clauses are guarded, so this re-runs harmlessly on every boot. Adding a
-- column with a DEFAULT is metadata-only: existing parts read '' for it, which
-- is exactly "not an issue", so no mutation and no backfill is needed. The
-- index only covers parts written from now on, and older parts hold nothing
-- it could match. See SCHEMA.md R1 (an additional column with a DEFAULT is
-- ours to add) and R17.
ALTER TABLE otel_logs
    ADD COLUMN IF NOT EXISTS IssueFingerprint String DEFAULT '' CODEC(ZSTD(1)) AFTER ProjectId,
    ADD INDEX IF NOT EXISTS idx_issue_fingerprint IssueFingerprint TYPE bloom_filter(0.001) GRANULARITY 1
