{{-- The styleguide's own Inertia root view.

     The styleguide is the one public surface that cannot be Blade: it renders
     the app's live Vue components. So instead of rebuilding the public header
     and footer in Vue — two copies of chrome that would immediately drift —
     the Inertia mount point is wrapped in the shared Blade chrome here, and
     the controller points at this root view. A visitor should not be able to
     tell that this page is Inertia and the docs beside it are not.

     `current="styleguide"` deliberately matches nothing in the header nav:
     the styleguide is linked from the footer, not sold from the header. --}}
    <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      @class(['dark' => ($appearance ?? 'system') === 'dark'])
      data-font="{{ $font ?? 'geist' }}">
<head>
    <x-public.head title="Styleguide — {{ config('app.name', 'Bilis') }}"
                   description="The Bilis design system: the neutral ladder, the semantic tokens, the severity ramp, and every component the app ships — live.">
        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
    </x-public.head>
</head>
<body class="min-h-dvh bg-background font-sans text-foreground antialiased">
<x-public.header wide
                 current="styleguide" />

<main>
    <x-inertia::app />
</main>

<x-public.footer wide />
</body>
</html>
