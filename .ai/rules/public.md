---
paths:
  - resources/views/components/public/head.blade.php
---

# Public

## Canonical, robots and sitemap are rendered, not static
The shared public head emits `<link rel="canonical">` built from `config('app.url')` + path (no query, no request host), and takes an optional `robots` prop (styleguide passes `noindex, follow`). `app.blade.php` (the Inertia root: app + auth screens) is `noindex, nofollow`. `/robots.txt` and `/sitemap.xml` are routes in `SitemapController` (there is no `public/robots.txt`, it would shadow the route) so a self-hosted instance advertises its own origin; add new public pages to the sitemap there.
