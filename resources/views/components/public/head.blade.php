{{-- Everything in the <head> that every public surface shares.

     Kept in one place because the parts that must agree — the pre-paint
     ground and the `theme-color` metas against `--background`, the social
     card, the appearance script — are exactly the parts that silently drift
     when each layout carries its own copy. The slot takes whatever one
     surface needs on top. --}}
@props([
    'title',
    'description',
])

<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>{{ $title }}</title>
<meta name="description"
      content="{{ $description }}">

<x-social-meta :title="$title"
               :description="$description" />

{{-- Public pages follow the operating system only; the appearance toggle lives in the app. --}}
<script nonce="{{ $cspNonce ?? '' }}">
    if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark');
    }
</script>

{{-- Paint the page ground before the stylesheet lands, so there is no flash.
     These two values are `--background` in each mode and must stay equal to
     it; tests/Feature/MarketingHeroTest.php pins them. --}}
<style nonce="{{ $cspNonce ?? '' }}">
    html {
        background-color: hsl(225 20% 97%);
    }

    html.dark {
        background-color: hsl(225 14% 8%);
    }
</style>

<link rel="icon"
      href="/favicon.ico"
      sizes="16x16 32x32 48x48 64x64">
<link rel="icon"
      href="/favicon.svg"
      type="image/svg+xml">
<link rel="apple-touch-icon"
      sizes="180x180"
      href="/apple-touch-icon.png">

<meta name="theme-color"
      content="#f6f7f9"
      media="(prefers-color-scheme: light)">
<meta name="theme-color"
      content="#111317"
      media="(prefers-color-scheme: dark)">

{{ $slot }}

@fonts

@if (config('bilis.analytics.script_url'))
    <script defer
            nonce="{{ $cspNonce ?? '' }}"
            src="{{ config('bilis.analytics.script_url') }}"
            data-website-id="{{ config('bilis.analytics.website_id') }}"></script>
@endif
