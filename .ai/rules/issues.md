---
paths:
  - 'app/Services/Issues/**'
---

# Issues

## Issue identity, state and reads
IssueFingerprint is written only by LogWriter via ErrorFingerprinter::issueFingerprint() (ERROR+ and an exception class or a stack); Autofix fingerprints with the same function, so changing the algorithm re-keys every issue and every fix-job cooldown — treat it as a migration. Status lives in the app `issues` table (row created on first decision); "regressed" is derived in IssueState (resolved && lastSeen > resolved_at), never stored. ClickHouse reads follow SCHEMA.md R17 (re-aggregate by key, aggregates aliased away from column names) and R18 (nominate from issue_hourly, headline from issue_summary); event reads go through LogQuery::issueEvent/issueEvents/issueTags so R4's conditions() stays the one base predicate. SQL is proven only by tests/Feature/Issues/IssueQueryLiveTest.php.
