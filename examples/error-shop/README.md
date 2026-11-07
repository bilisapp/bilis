# Error shop

A tiny storefront that fails on purpose and reports every failure through the
official Sentry browser SDK, so the envelope ingest and the Issues pages can be
exercised with real SDK traffic rather than hand-written payloads.

It is a static page with no build step and no dependency on the app.

## Run it

1. In Bilis, open a project and create an API key. Copy its **DSN**.
2. On the same project page, add `http://127.0.0.1:8777` under **Browser
   origins**. Browser SDK traffic is refused from any origin not listed there.
3. Serve the shop:

   ```sh
   php -S 127.0.0.1:8777 -t examples/error-shop
   ```

4. Open `http://127.0.0.1:8777/?dsn=<your DSN>` (or paste the DSN into the form;
   the browser remembers it) and click the buttons.
5. Open **Issues** in Bilis.

Local development needs ClickHouse running for any of this to land
(`php artisan clickhouse:migrate` first).

## What each button does

| Button | Error | How it is reported |
| --- | --- | --- |
| View cart summary | `TypeError` reading a missing property, three frames deep (`js/cart.js`) | unhandled, by the SDK's global handler |
| Pay for order | custom `PaymentDeclinedError` (`js/checkout.js`) | handled: `captureException` with a tag and an `order` context |
| Load recommendations | `Error` whose `cause` is a `SyntaxError` from parsing a truncated `reco.json` | handled, with the cause chain |
| Apply coupon | `TypeError` thrown inside an un-awaited async function | unhandled promise rejection |
| Estimate shipping | `RangeError` from `new Array(-3)` inside a `setTimeout` | unhandled |

Clicks, console lines and `fetch` calls before each error arrive as breadcrumbs.

## Scenarios

Everything is set from the query string, so a scenario is a link:

| Parameter | Default | Effect |
| --- | --- | --- |
| `dsn` | last one used | which project the events go to |
| `user` | `cus_9fK2` | the reported user, so "users affected" counts move |
| `release` | `storefront@1.5.0` | first/last release on the issue |
| `env` | `production` | environment tag and filter |
| `region` | `eu-west` | a custom tag, shown under the event's tags |

For example, the same bug from a second user on an older release in staging:
`/?user=cus_1aQ7&release=storefront@1.4.0&env=staging`.

The SDK drops an event identical to the one it just sent (its dedupe
integration), so clicking one button ten times in a row reports it once or twice.
Change the `user` or reload the page between clicks to see the counts climb.
