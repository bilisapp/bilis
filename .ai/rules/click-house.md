---
paths:
  - 'app/Services/ClickHouse/**'
---

# Click House

## ClickHouse access goes through ClickHouseClient over HTTP
There is no ClickHouse PHP driver in this project by design — everything goes over the HTTP interface via the `Http` facade in `App\Services\ClickHouse\ClickHouseClient`.

Never interpolate user values into SQL. Use ClickHouse server-side query parameters: `{name:Type}` in the statement and pass values through the `$params` array of `select()`, which sends them as `param_<name>` query parameters.

Inserts always use `FORMAT JSONEachRow` with `async_insert=1` / `wait_for_async_insert=0`, so a successful `insert()` means "queued", not "durable".

Failures throw `ClickHouseException`. Call `isOverload()` to decide whether to map to a 503 (connection failure, 429/502/503/504, or ClickHouse codes 159/202/203/209/210/241/252); a statement error such as code 62 returns false.

Schema lives in `database/clickhouse/*.sql` — one idempotent statement per file, applied in filename order by `php artisan clickhouse:migrate`.

## The logs schema and its rules live in database/clickhouse/SCHEMA.md
`database/clickhouse/SCHEMA.md` is the source of truth for the `otel_logs` table: the pinned collector tag it mirrors, the exact DDL, and rules R1–R9. Read it before touching the DDL or any query against the table. The DDL file is a copy of §2 with `IF NOT EXISTS` added; keep the two in step.

Column names and types are the collector exporter's (R1) and are not ours to rename — PascalCase included. `ORDER BY`, `PARTITION BY`, `TTL`, indexes and the added `ProjectId` column are ours. Sort key: `(ProjectId, Timestamp, ServiceName)` — a deliberate divergence from upstream's leading `toStartOfFiveMinutes(Timestamp)` bucket, which buys little behind a tenant column and would break read-in-order for live tail; `idx_service` compensates for service-filtered queries.

`ProjectId` is `LowCardinality(String) DEFAULT ''`. The `DEFAULT` exists only so a stock exporter INSERT is *valid* — Bilis always writes the column explicitly (R2). It is clustering, never isolation (R3): do not describe the sort key as a tenancy boundary.

**Bilis is deployed and `otel_logs` holds real data**, so a DDL file may no longer be edited in place and expected to take effect: `CREATE TABLE IF NOT EXISTS` cannot alter a table that exists. A schema change now needs two things — the `CREATE` updated so a fresh install is correct, *and* a numbered `ALTER` file so a deployed one converges. Both clauses of the ALTER are guarded (`IF EXISTS` / `IF NOT EXISTS`), because the file re-runs on every boot and `docker-entrypoint.sh` runs `clickhouse:migrate` once per container role — a deploy can issue it three times at once. `0005_alter_otel_logs_body_index.sql` is the worked example.

Anything expensive stays out of `clickhouse:migrate` and gets its own command: `clickhouse:materialize-index` rebuilds index files across every existing part, which must happen once, deliberately, not on each boot.

There is still no backup (SCHEMA.md R7), so there is no restore path before a bad ALTER.

## Timezone: every request pins session_timezone=UTC

DateTime64 columns carry no timezone; ClickHouse parses timestamp strings in
the session timezone, which defaults to the SERVER timezone. The app formats
and parses all timestamps as naive UTC (the frontend appends `Z`), so
`ClickHouseClient::send()` pins `session_timezone=UTC` on every request.
Never remove it, and never format a timestamp for ClickHouse in any zone but
UTC. The Timestamp column is additionally declared `DateTime64(9, 'UTC')`
because session_timezone governs parsing and now() but NOT the rendering of a
naive DateTime64 column — without the column timezone, SELECTs return strings
in the server's timezone (verified on 26.9). Test: "every request pins the session timezone to UTC".
