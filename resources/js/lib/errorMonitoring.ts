import { API_KEY_PLACEHOLDER } from '@/lib/serverAgent';

/**
 * What a snippet shows in place of a DSN when the project has no key yet. The
 * DSN is the public half of a key, so once a key exists the real one is used.
 */
export function dsnPlaceholder(origin: string): string {
    try {
        const url = new URL(origin);

        return `${url.protocol}//bilis_pk_YOUR_PUBLIC_KEY@${url.host}/1`;
    } catch {
        return 'https://bilis_pk_YOUR_PUBLIC_KEY@bilis.example.com/1';
    }
}

export const ERROR_MONITORING_TABS = [
    { id: 'workers-otlp', label: 'Workers · OTLP export' },
    { id: 'workers-sdk', label: 'Workers · Sentry SDK' },
    { id: 'node', label: 'Node.js' },
    { id: 'browser', label: 'Browser' },
    { id: 'python', label: 'Python' },
    { id: 'laravel', label: 'Laravel' },
] as const;

export type ErrorMonitoringTab = (typeof ERROR_MONITORING_TABS)[number]['id'];

export type ErrorMonitoringSnippetOptions = {
    /** The Bilis origin the OTLP endpoints hang off. */
    origin: string;
    /** The project's DSN; the placeholder is used without one. */
    dsn?: string | null;
    /** The service name the events are tagged with. */
    service?: string;
};

/**
 * Every setup snippet the error-monitoring card offers, keyed by tab.
 *
 * Each SDK sends no transactions (`traces_sample_rate` 0): the envelope
 * endpoint stores errors only and would count them and drop them. Each one
 * also tags a `service`, because the wire format has no service of its own
 * and a Worker reports no `server_name` to fall back to.
 */
export function errorMonitoringSnippets(
    options: ErrorMonitoringSnippetOptions,
): Record<ErrorMonitoringTab, string> {
    const origin = options.origin.trim().replace(/\/+$/, '');
    const dsn = options.dsn?.trim()
        ? options.dsn.trim()
        : dsnPlaceholder(origin);
    const service = options.service?.trim() ? options.service.trim() : 'my-app';

    return {
        'workers-otlp': `# Cloudflare dashboard → Workers & Pages → Observability
#   → Destinations → Add destination. One per signal:

Name:           bilis-traces
Type:           Traces
OTLP endpoint:  ${origin}/api/v1/traces
Custom header:  Authorization = Bearer ${API_KEY_PLACEHOLDER}

Name:           bilis-logs
Type:           Logs
OTLP endpoint:  ${origin}/api/v1/logs
Custom header:  Authorization = Bearer ${API_KEY_PLACEHOLDER}

// wrangler.jsonc — then redeploy the Worker
{
  "observability": {
    "traces": { "enabled": true, "destinations": ["bilis-traces"], "persist": false },
    "logs": { "enabled": true, "destinations": ["bilis-logs"], "persist": false }
  }
}`,
        'workers-sdk': `npm install @sentry/cloudflare

// wrangler.jsonc
{
  "compatibility_flags": ["nodejs_compat"],
  "vars": { "SENTRY_DSN": "${dsn}" }
}

// src/index.ts
import * as Sentry from '@sentry/cloudflare';

export default Sentry.withSentry(
  (env: Env) => ({
    dsn: env.SENTRY_DSN,
    tracesSampleRate: 0, // Bilis stores errors; send traces over OTLP
    initialScope: { tags: { service: '${service}' } },
  }),
  {
    async fetch(request, env, ctx) {
      return new Response('Hello from the edge');
    },
  } satisfies ExportedHandler<Env>,
);`,
        node: `npm install @sentry/node

// instrument.js — import it before anything else
import * as Sentry from '@sentry/node';

Sentry.init({
  dsn: '${dsn}',
  tracesSampleRate: 0,
  initialScope: { tags: { service: '${service}' } },
});`,
        browser: `npm install @sentry/browser

import * as Sentry from '@sentry/browser';

// List this page's origin under Browser origins below first.
Sentry.init({
  dsn: '${dsn}',
  tracesSampleRate: 0,
  initialScope: { tags: { service: '${service}' } },
});`,
        python: `pip install sentry-sdk

import sentry_sdk

sentry_sdk.init(
    dsn="${dsn}",
    traces_sample_rate=0,
)
sentry_sdk.set_tag("service", "${service}")`,
        laravel: `composer require sentry/sentry-laravel

# .env
SENTRY_LARAVEL_DSN=${dsn}
SENTRY_TRACES_SAMPLE_RATE=0

// bootstrap/app.php
use Sentry\\Laravel\\Integration;

->withExceptions(function (Exceptions $exceptions) {
    Integration::handles($exceptions);
})`,
    };
}
