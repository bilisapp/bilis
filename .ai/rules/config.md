---
paths:
  - config/legal.php
---

# Config

## Stripe Managed Payments: Stripe is the merchant of record, not us
The hosted service sells through Stripe Managed Payments, so Stripe — not samko labs — is the merchant of record. Stripe calculates, collects, files and remits sales tax/VAT/GST in the countries it covers, issues receipts and invoices directly, and handles disputes and transaction-level support. Customers see "Sold through Link", a `LINK.COM*` statement descriptor, and manage subscriptions at link.com.

Consequences: `legal.operator.vat_id` stays empty (we are not the party charging the customer VAT) and every page that mentions VAT renders that clause only when it is filled. Terms §8 and Privacy §3.5 describe this arrangement — keep them in step with `legal.payments` rather than hardcoding "Stripe" or "Link" into prose.

When billing is eventually built (out of v1 scope): Managed Payments requires Stripe Checkout or Payment Links — no Connect, no Elements/embedded components, no custom checkout domain, and subscriptions cannot be created outside Checkout/Payment Links. Stripe may also refund a transaction within 60 days without our approval if we do not answer its support escalation within 48 hours, so the support address in the Stripe Dashboard must be monitored.

https://docs.stripe.com/payments/managed-payments/how-it-works
