---
paths:
  - app/Http/Middleware/SecurityHeaders.php
---

# Middleware

## Nonce-based CSP: every executable script tag must carry the nonce
`SecurityHeaders` calls `Vite::useCspNonce()` before the response renders, so `@vite` and `@fonts` stamp the nonce themselves and Blade reads it from the shared `$cspNonce`. Any new inline `<script>` or third-party script tag must add `nonce="{{ $cspNonce ?? '' }}"` or `'strict-dynamic'` will block it. Inertia's `data-page` tag is `type="application/json"` — a data block browsers never execute, so it needs no nonce.

`style-src` keeps `'unsafe-inline'` on purpose: Vue and reka-ui write `style` attributes for positioning, which a nonce cannot cover. Never add a nonce to `style-src` — that makes browsers ignore `'unsafe-inline'` and breaks every popover.

A CSP host-source has no form for an IPv6 literal, so when the Vite dev server binds to `http://[::1]:5173` the policy stands down entirely rather than serve a silently broken page. `vite.config.ts` pins `server.host: 'localhost'` so the real policy is exercised in development. Tests must call `Vite::useHotFile()` on a non-existent path to assert what production serves.

## Horizon's dashboard is the one place `'unsafe-eval'` is allowed
Horizon mounts an in-DOM template, so Vue compiles it at runtime with `new Function` — a nonce cannot cover that, and without `'unsafe-eval'` the dashboard throws `EvalError` and renders blank. `SecurityHeaders::isHorizonDashboard()` scopes the exception to `config('horizon.path')`; never widen it to the application, which evaluates no strings anywhere.

The other two Horizon violations are fixed at the source, not in the policy: `HorizonServiceProvider` hands `Horizon::cspNonce()` the request nonce via a `horizon::layout` composer (the inline module script is blocked by `'strict-dynamic'` otherwise), and `resources/views/vendor/horizon/layout.blade.php` is the vendor layout minus its two `fonts.bunny.net` tags. Re-copy that view, minus those tags, when upgrading Horizon.
