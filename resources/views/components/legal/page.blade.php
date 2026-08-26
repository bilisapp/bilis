@props([
    'title',
    'description' => null,
    'summary' => null,
])

{{--
    Shared chrome for the legal documents. The prose styling lives here as
    container-scoped variants rather than in app.css, so these long documents
    stay plain readable markup and the design tokens keep doing the work.
--}}
<x-layouts.marketing :title="$title" :description="$description">
    <article class="mx-auto max-w-3xl px-6 py-16 sm:py-20">
        <header>
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ $title }}</h1>

            <p class="mt-4 text-sm text-muted-foreground">
                Effective {{ config('legal.effective_date') }}
                <span aria-hidden="true" class="mx-1.5">·</span>
                Last updated {{ config('legal.last_updated') }}
            </p>

            @if ($summary)
                <div class="mt-8 rounded-xl border border-border bg-card p-5 text-sm leading-relaxed text-muted-foreground">
                    <p class="font-medium text-foreground">In short</p>
                    <div class="mt-2 space-y-2">{{ $summary }}</div>
                </div>
            @endif
        </header>

        <div @class([
            'mt-12 text-sm leading-relaxed text-muted-foreground',
            // Headings
            '[&_h2]:mt-12 [&_h2]:scroll-mt-24 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-foreground',
            '[&_h3]:mt-8 [&_h3]:text-sm [&_h3]:font-semibold [&_h3]:text-foreground',
            // Blocks
            '[&_p]:mt-4 [&_ul]:mt-4 [&_ol]:mt-4 [&_ul]:space-y-2 [&_ol]:space-y-2',
            '[&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-5 [&_ol]:pl-5 [&_li]:pl-1',
            // Emphasis and links
            '[&_strong]:font-medium [&_strong]:text-foreground',
            '[&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4 hover:[&_a]:text-primary',
            // Tables read as a surface, per the three-level hierarchy.
            '[&_table]:mt-6 [&_table]:w-full [&_table]:border-collapse [&_table]:text-left',
            '[&_th]:border-b [&_th]:border-border [&_th]:pb-2 [&_th]:pr-4 [&_th]:font-medium [&_th]:text-foreground',
            '[&_td]:border-b [&_td]:border-border [&_td]:py-3 [&_td]:pr-4 [&_td]:align-top',
        ])>
            {{ $slot }}
        </div>

        <footer class="mt-16 border-t border-border pt-8 text-xs text-muted-foreground">
            <p>
                Questions about this document: <a href="mailto:{{ config('legal.contact.general') }}" class="font-medium text-foreground underline underline-offset-4">{{ config('legal.contact.general') }}</a>.
            </p>
        </footer>
    </article>
</x-layouts.marketing>
