---
title: Severity
description: The severity levels, the aliases Bilis accepts, and what happens to a level it does not know.
order: 4
---

Severity is stored twice: as an OpenTelemetry `SeverityNumber` (1–24) and as the
`SeverityText` your client sent. The number is what filters and colours the
viewer; the text is kept verbatim so nothing is lost.

## The levels

| Level   | Number | Range |
| ------- | ------ | ----- |
| `TRACE` | 1      | 1–4   |
| `DEBUG` | 5      | 5–8   |
| `INFO`  | 9      | 9–12  |
| `WARN`  | 13     | 13–16 |
| `ERROR` | 17     | 17–20 |
| `FATAL` | 21     | 21–24 |

Each band has four steps, so `18` is `ERROR2` and `10` is `INFO2`. This is the
OpenTelemetry logs data model, unchanged.

## Accepted names

You do not have to speak OTel. These names all resolve to a number:

| You send                                      | Number | Level  |
| --------------------------------------------- | ------ | ------ |
| `trace`, `trace2`–`trace4`, `verbose`         | 1–4    | TRACE  |
| `debug`, `debug2`–`debug4`                    | 5–8    | DEBUG  |
| `info`, `information`, `informational`, `log` | 9      | INFO   |
| `notice`                                      | 10     | INFO2  |
| `warn`, `warning`                             | 13     | WARN   |
| `error`, `err`, `severe`                      | 17     | ERROR  |
| `critical`, `crit`                            | 18     | ERROR2 |
| `alert`                                       | 19     | ERROR3 |
| `fatal`, `emergency`, `emerg`, `panic`        | 21     | FATAL  |

Matching is case-insensitive and whitespace is trimmed, so `WARNING`, `Warning`
and `warn` are the same thing. The syslog names (`notice`, `crit`, `alert`,
`emerg`) and the PSR-3 / Monolog names both work, which is what makes the
Laravel and OTel paths agree without a translation table on your side.

## Number wins over text

When a record carries both a severity number and a severity text:

- **A number in 1–24 is authoritative.** The text is stored as-is next to it,
  even when the two disagree — your `"CARD_DECLINED"` stays `"CARD_DECLINED"`.
- If the number is missing or out of range, the text is looked up in the table
  above.
- If neither yields anything, the number is `0` (`UNSPECIFIED`) and the text is
  whatever you sent, or empty.

**An unknown level never rejects a record.** `severity: "spicy"` stores the line
with number `0` and text `spicy`; you will still find it by service, time and
full-text search. Losing a log line over a bad enum value would be a worse
outcome than an unfiltered one.

> **Note:** severity `0` is excluded from severity filters by definition — it is
> not a level. If lines are missing from a filtered view, check what your
> shipper puts in the level field.
