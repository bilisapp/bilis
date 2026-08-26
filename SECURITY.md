# Security Policy

Bilis ingests and stores application logs. Logs routinely contain secrets people
did not mean to log — tokens, session identifiers, personal data. We treat a
vulnerability in Bilis as a vulnerability in whatever its users are running, and
we would rather hear about a problem early and awkwardly than late and politely.

## Reporting a vulnerability

Email **security@bilis.app**. If you prefer an encrypted channel, say so in a
first message with no details and we will send you a key.

Please do not open a public GitHub issue, pull request, or discussion for a
security problem. Public reports put every self-hosted instance at risk before a
fix exists.

Useful things to include, none of them mandatory:

- What the issue is and roughly how bad you think it is.
- A version, commit SHA, or `bilis.app` timestamp.
- Steps to reproduce, or a proof of concept.
- Whether you have told anyone else, and whether you intend to publish.

## What happens next

| When | What we do |
| --- | --- |
| Within 3 working days | Acknowledge your report and tell you who is handling it. |
| Within 10 working days | Give you our assessment: confirmed or not, severity, rough fix timeline. |
| While we work | Keep you updated at least every 10 working days. |
| On release | Ship the fix, publish an advisory, and credit you unless you would rather we did not. |

We aim to fix critical issues within 14 days and everything else within 90. If a
fix will take longer than that, we will say so and explain why rather than let
the thread go quiet.

## Coordinated disclosure

We ask for 90 days from your report before public disclosure, or until a fix
ships — whichever comes first. If we are dragging our feet, tell us and publish;
a deadline you have to enforce is still better than a vulnerability nobody knows
about. We will not ask for an extension more than once, and we will never ask you
to stay quiet indefinitely.

We publish advisories as [GitHub Security Advisories](https://github.com/bilisapp/bilis/security/advisories)
with a CVE where one is warranted.

## Scope

**In scope**

- The Bilis application code in this repository.
- The hosted service at `bilis.app` and its subdomains.
- The ingest endpoints, the API-key authentication path, and any way to read
  another project's or another team's logs.
- The ClickHouse query layer — anything that gets a value into SQL without going
  through a server-side parameter placeholder.

**Out of scope**

- Denial of service through sheer volume. Please do not load-test `bilis.app`.
- Findings from automated scanners with no demonstrated impact.
- Missing hardening headers or a TLS configuration nit with no exploit path.
- Social engineering of us, our users, or our providers.
- Vulnerabilities in a self-hosted instance caused by that operator's own
  configuration — though if Bilis makes an insecure setup easy or default, that
  is a real finding and we want it.

## Safe harbour

If you follow this policy, we will not pursue or support legal action against
you for your research. Specifically: test only against your own instance or
against accounts you control on `bilis.app`; do not access, modify, or retain
other people's data; do not degrade the service for others; and stop as soon as
you have demonstrated the problem.

If a third party brings action against you for research conducted under this
policy, we will make it clear that your work was authorised.

We do not run a paid bug bounty. We do credit every reporter who wants it.

## Supported versions

Bilis is pre-1.0. Security fixes land on `main` and in the most recent release
only. There are no backports to older tags yet — when a stable release line
exists, this section will say which versions are maintained and for how long.

Self-hosters are responsible for applying updates. Watch the repository's
releases, or subscribe to advisories, to hear about security releases.

## What we do on our side

- All ClickHouse queries use server-side parameter placeholders — values are
  never interpolated into SQL.
- API keys are stored only as SHA-256 hashes; the plaintext key is shown once at
  creation and never again.
- Project scoping is derived from the authenticated API key on ingest and from
  the current team on read. A project identifier from a request payload never
  reaches a query.
- Dependencies are monitored for known vulnerabilities and updated on a regular
  cadence.

## Regulatory reporting

Where the EU Cyber Resilience Act applies to us, actively exploited
vulnerabilities and severe incidents affecting the hosted service are reported to
ENISA and the relevant CSIRT on the statutory timeline (early warning within 24
hours, notification within 72 hours, final report within 14 days of a corrective
measure). Reporting to a regulator does not replace telling affected users — we
will do both.
