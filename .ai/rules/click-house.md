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

There is no production data yet, so the DDL file is edited in place and the operator drops the dev table before re-running `clickhouse:migrate`. That stops being true the moment anything real is stored.
