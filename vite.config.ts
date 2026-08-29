import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                // Blade-only marketing enhancement; never pulls in Inertia.
                'resources/js/marketing/hero-shader.ts',
            ],
            refresh: true,
            fonts: [
                bunny('IBM Plex Mono', {
                    weights: [400, 500, 600],
                }),
                bunny('Geist', {
                    weights: [400, 500, 600],
                }),
                bunny('Geist Mono', {
                    weights: [400, 500],
                }),
            ],
        }),
        inertia({ ssr: false }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
            command: process.env.WAYFINDER_COMMAND,
        }),
    ],
    server: {
        // Named rather than left to resolve to an IPv6 literal: a CSP
        // host-source has no form for `[::1]`, so a dev server that binds
        // there cannot be allowed by the app's Content-Security-Policy.
        host: 'localhost',
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
});
