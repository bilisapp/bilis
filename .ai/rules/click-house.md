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
