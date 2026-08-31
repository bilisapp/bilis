---
paths:
  - config/legal.php
  - config/opentelemetry.php
---

# Config

## Stripe Managed Payments: Stripe is the merchant of record, not us
The hosted service sells through Stripe Managed Payments, so Stripe — not samko labs — is the merchant of record. Stripe calculates, collects, files and remits sales tax/VAT/GST in the countries it covers, issues receipts and invoices directly, and handles disputes and transaction-level support. Customers see "Sold through Link", a `LINK.COM*` statement descriptor, and manage subscriptions at link.com.

Consequences: `legal.operator.vat_id` stays empty (we are not the party charging the customer VAT) and every page that mentions VAT renders that clause only when it is filled. Terms §8 and Privacy §3.5 describe this arrangement — keep them in step with `legal.payments` rather than hardcoding "Stripe" or "Link" into prose.

When billing is eventually built (out of v1 scope): Managed Payments requires Stripe Checkout or Payment Links — no Connect, no Elements/embedded components, no custom checkout domain, and subscriptions cannot be created outside Checkout/Payment Links. Stripe may also refund a transaction within 60 days without our approval if we do not answer its support escalation within 48 hours, so the support address in the Stripe Dashboard must be monitored.

https://docs.stripe.com/payments/managed-payments/how-it-works

## Bilis traces itself, and exporting to yourself has three traps
`keepsuit/laravel-opentelemetry` instruments Bilis; spans go to Bilis' own `/api/v1/traces`. Three things in `config/opentelemetry.php` are load-bearing and are NOT package defaults:

1. **`excluded_paths` must contain `api/v1/traces`.** The export is an inbound POST like any other, so tracing it produces spans, which are exported, which produce spans. Non-converging, and one request seeds it. The other ingest routes and `up` are excluded for volume, not recursion.
2. **Metrics and logs exporters are `null`, not `otlp`.** Metrics are out of scope (the package default posts to `/v1/metrics`, gets a 404, retries 3x and prints a stack trace per request); logs already leave through the `bilis` Monolog channel.
3. **Timeout 3s / 1 retry, not 10s / 3.** The export is synchronous at request end against the same PHP worker pool, so every traced request occupies two workers. The package defaults let one failing sink hold a worker 30s and exhaust the pool — the symptom is a 502 on an application with no bug in it. Prefer a Collector in front if you can.

Also: the SDK is opt-in here (`disabled` defaults to true unless `OTEL_EXPORTER_OTLP_ENDPOINT` is set), `phpunit.xml` pins `OTEL_SDK_DISABLED=true`, `ConsoleInstrumentation` is a whitelist (enabled + empty `commands` traces nothing), and `config/logging.php` sets the `bilis` channel's `service` from `OTEL_SERVICE_NAME` so logs and spans name one service, not two.
