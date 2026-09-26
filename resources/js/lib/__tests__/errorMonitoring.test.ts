import { describe, expect, it } from 'vitest';
import {
    dsnPlaceholder,
    ERROR_MONITORING_TABS,
    errorMonitoringSnippets,
} from '@/lib/errorMonitoring';

describe('errorMonitoringSnippets', () => {
    it('points the Workers OTLP destinations at both signal endpoints', () => {
        const { 'workers-otlp': snippet } = errorMonitoringSnippets({
            origin: 'https://bilis.app/',
        });

        expect(snippet).toContain('https://bilis.app/api/v1/traces');
        expect(snippet).toContain('https://bilis.app/api/v1/logs');
        expect(snippet).toContain('Authorization = Bearer bilis_YOUR_API_KEY');
        expect(snippet).toContain('"destinations": ["bilis-traces"]');
        expect(snippet).toContain('"destinations": ["bilis-logs"]');
    });

    it('fills the real DSN into every SDK snippet', () => {
        const dsn = 'https://bilis_pk_abc@bilis.app/1';
        const snippets = errorMonitoringSnippets({
            origin: 'https://bilis.app',
            dsn,
            service: 'checkout-edge',
        });

        for (const tab of [
            'workers-sdk',
            'node',
            'browser',
            'python',
            'laravel',
        ] as const) {
            expect(snippets[tab]).toContain(dsn);
        }

        expect(snippets['workers-sdk']).toContain('nodejs_compat');
        expect(snippets['workers-sdk']).toContain('tracesSampleRate: 0');
        expect(snippets['workers-sdk']).toContain("service: 'checkout-edge'");
        expect(snippets.python).toContain('traces_sample_rate=0');
    });

    it('falls back to a placeholder DSN on the current host', () => {
        expect(dsnPlaceholder('http://bilis.test:8080')).toBe(
            'http://bilis_pk_YOUR_PUBLIC_KEY@bilis.test:8080/1',
        );
        expect(dsnPlaceholder('')).toBe(
            'https://bilis_pk_YOUR_PUBLIC_KEY@bilis.example.com/1',
        );
        expect(
            errorMonitoringSnippets({ origin: 'https://bilis.app', dsn: ' ' })
                .node,
        ).toContain("dsn: 'https://bilis_pk_YOUR_PUBLIC_KEY@bilis.app/1'");
    });

    it('has a snippet for every tab', () => {
        const snippets = errorMonitoringSnippets({
            origin: 'https://bilis.app',
        });

        for (const { id } of ERROR_MONITORING_TABS) {
            expect(snippets[id]).not.toBe('');
        }
    });
});
