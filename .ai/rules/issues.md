---
paths:
  - 'app/Services/Issues/**'
---

# Issues

## Issue identity, state and reads
IssueFingerprint is written only by LogWriter via ErrorFingerprinter::issueFingerprint() (ERROR+ and a strictly-named exception type or a stack with located frames — `isIssue()` deliberately ignores `exceptionClass()`'s loose `Word: …` headline and bare `error=` fallbacks, which Autofix keeps); Autofix fingerprints with the same function, so changing the algorithm re-keys every issue and every fix-job cooldown — treat it as a migration. Status lives in the app `issues` table (row created on first decision); "regressed" is derived in IssueState (resolved && lastIngested > resolved_at — arrival time, never the client-supplied event Timestamp), never stored. ClickHouse reads follow SCHEMA.md R17 (re-aggregate by key, aggregates aliased away from column names) and R18 (nominate from issue_hourly, headline from issue_summary); event reads go through LogQuery::issueEvent/issueEvents/issueTags so R4's conditions() stays the one base predicate. SQL is proven only by tests/Feature/Issues/IssueQueryLiveTest.php.

## Events are identified by (Timestamp, event key), and the issue page reloads only the event
Same-second events are common, so every issue event read orders by `(Timestamp, LogQuery::ISSUE_EVENT_KEY)` and links carry `?event=&key=`; the controller drops a key outside UInt64 rather than letting ClickHouse 500. Stepping between events and resolving are Inertia partial reloads (`only: ['event','exception']` / `['issue']`) — the histogram, tags and recent events describe the issue, and the tag breakdown is a bounded sample (newest 2,000 events) because envelope attribute maps are large.

## Structured attributes stay on the issue page
`exception.frames`, `exception.chain` and `breadcrumbs.json` (up to tens of KB per envelope row) are dropped by `LogQuery::mapRow()` for every read except `issueEvent()` (`keepRichAttributes: true`). Don't project them into list reads.

## The issue tables were edited in place only because they had not shipped
0015–0019 were rewritten on the feature branch before merge. Once merged and applied anywhere, they are append-only like every other schema file (.ai/rules/clickhouse.md).
