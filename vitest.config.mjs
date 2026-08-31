import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Unit tests for the pure TypeScript under resources/js/lib.
 *
 * Kept apart from vite.config.ts on purpose: that file boots Laravel's plugin,
 * Wayfinder generation and the font downloader, none of which a test of a
 * timestamp parser should have to pay for. Only the `@` alias is shared.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/__tests__/**/*.test.ts'],
    },
});
