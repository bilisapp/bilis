---
paths:
  - 'database/clickhouse/*.sql'
---

# Clickhouse

## Applied schema files are append-only
Never edit a database/clickhouse/*.sql file that has already shipped — not even the CREATE in 0001 to "keep it in sync". Every schema change is a new numbered file, doubly guarded (ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS / CREATE … IF NOT EXISTS), and fresh installs reach the final shape the same way deployed ones do. SCHEMA.md documents the effective table and names the file that added each change. Worked example: 0015_alter_otel_logs_issue_fingerprint.sql (the user's standing decision, 2026-11-07).
