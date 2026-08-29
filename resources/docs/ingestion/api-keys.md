---
title: API keys
description: Every key is a pair — a secret half for shippers and a public half for clients that carry their credentials in a URL.
order: 2
---

An API key belongs to one project, and the project it belongs to is the only one
it can ever write to. Issue keys from **Projects → your project → New API key**.

Each key is one credential with two halves. They are issued together, they are
revoked together, and they share a rate limit.

| Half   | Looks like     | Visibility            | Used by                                          |
| ------ | -------------- | --------------------- | ------------------------------------------------ |
| Secret | `bilis_…`      | Shown once, then only a hash is stored | Collectors, shippers, anything that can send a header |
| Public | `bilis_pk_…`   | Always visible on the project page      | Clients configured with a URL instead of a header     |

## The secret half

This is the one to reach for. Send it as a bearer token, or in `X-Bilis-Key` if
the client cannot set an `Authorization` header:

```bash
-H "Authorization: Bearer bilis_YOUR_API_KEY"
# or
-H "X-Bilis-Key: bilis_YOUR_API_KEY"
```

Bilis stores only a sha256 of it, so the plaintext is shown exactly once, when
the key is created. Lose it and you issue a new one — there is no way to read it
back, by design.

## The public half

Some clients are not configured with a header at all. They are handed a single
URL that carries the credential inside it, and they build their own requests
from it — the Sentry SDKs are the case Bilis supports today, through a
[Sentry-compatible endpoint](/docs/ingestion/sentry).

A credential in a URL is a credential that travels: into a deploy config, into a
container environment, and — for anything running in a browser — into the page
source. So the public half is stored in plaintext and stays readable on the
project page forever. Hashing it would make the URL unrecoverable without buying
any secrecy, because the URL itself is the disclosure.

### What it can do

Both halves authorise exactly one thing: **writing logs into their own
project**. Neither can read logs, list projects, or touch anything else, so a
public key that leaks costs you junk in one project's log stream — not access to
your data.

That is still worth acting on. Revoke the key and issue a new one; the shippers
using the secret half get the same treatment, which is the trade for keeping one
credential with two halves rather than two credentials to track.

For anything running in a browser there is a second lock, and it is not on the
key: a page may only post from an origin listed on the project. See
[Browser origins](/docs/ingestion/sentry#from-the-browser).

## Rate limits

Requests are counted per credential, not per project, so one noisy client cannot
starve another. Over the limit is a `429` with `Retry-After`, which every
exporter and SDK already treats as retryable. See
[Limits and behavior](/docs/reference/limits-and-behavior).

## Revoking

Revoking removes both halves at once. Anything still using either one starts
getting `401` immediately — there is no grace period, so issue the replacement
first if you cannot afford the gap.
