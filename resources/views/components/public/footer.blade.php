{{-- The one public footer, shared by every surface a logged-out visitor can
     reach: the mark and one line of what Bilis is, three short columns of
     links, and the operator's legal identity. The styleguide lives here
     rather than in the header: it is a resource for people building on
     Bilis, not a sales surface. --}}
@props(['wide' => false])

@php
    $columns = [
        'Product' => [
            ['label' => 'Features', 'href' => route('features')],
            ['label' => 'MCP', 'href' => route('features.mcp')],
            ['label' => 'Pricing', 'href' => route('pricing')],
            ['label' => 'vs Datadog', 'href' => route('compare.datadog')],
        ],
        'Learn' => [
            ['label' => 'Docs', 'href' => route('docs.index')],
            ['label' => 'Blog', 'href' => route('blog.index')],
            ['label' => 'Free tools', 'href' => route('tools.index')],
            ['label' => 'Styleguide', 'href' => route('styleguide')],
        ],
        'Company' => [
            ['label' => 'Contact', 'href' => route('contact.show')],
            ['label' => 'Source on GitHub', 'href' => config('bilis.github_url'), 'external' => true],
            ['label' => 'Terms', 'href' => route('terms')],
            ['label' => 'Privacy', 'href' => route('privacy')],
            ['label' => 'Security', 'href' => config('bilis.github_url').'/blob/main/SECURITY.md', 'external' => true],
        ],
    ];
@endphp

<footer {{ $attributes->merge(['class' => 'border-t border-border']) }}>
    <div @class([
        'mx-auto flex flex-col gap-10 px-4 py-12 sm:flex-row sm:justify-between sm:px-6',
        'max-w-7xl' => $wide,
        'max-w-content' => ! $wide,
    ])>
        <div class="flex max-w-xs flex-col gap-3">
            <img src="/logo-mark.svg"
                 alt=""
                 class="h-5 w-auto self-start"
                 width="20790"
                 height="4080">
            <p class="text-xs leading-relaxed text-muted-foreground">
                {{ config('app.name', 'Bilis') }} — self-hosted logs, traces and metrics your coding agent can read.
            </p>
        </div>

        <nav class="grid grid-cols-2 gap-x-12 gap-y-8 sm:grid-cols-3" aria-label="Footer">
            @foreach ($columns as $heading => $links)
                <div class="flex flex-col gap-2">
                    <p class="eyebrow text-foreground">{{ $heading }}</p>
                    @foreach ($links as $link)
                        <a href="{{ $link['href'] }}"
                           @if ($link['external'] ?? false) target="_blank" rel="noopener noreferrer" @endif
                           class="text-sm text-muted-foreground transition-colors hover:text-foreground">{{ $link['label'] }}</a>
                    @endforeach
                </div>
            @endforeach
        </nav>
    </div>

    {{-- Operator identification, as Slovak and EU rules require on a business website. --}}
    <div class="border-t border-border">
        <div @class([
            'mx-auto px-4 py-6 text-xs leading-relaxed text-muted-foreground sm:px-6',
            'max-w-7xl' => $wide,
            'max-w-content' => ! $wide,
        ])>
            <p>
                {{ config('app.name', 'Bilis') }} is operated by
                <span class="text-foreground">{{ config('legal.operator.name') }}</span>,
                {{ config('legal.operator.address') }}, {{ config('legal.operator.country') }}.
                Source-available under FSL-1.1-ALv2.
            </p>
            <p class="mt-1">
                IČO {{ config('legal.operator.company_id') }} ·
                DIČ {{ config('legal.operator.tax_id') }}
                @if (config('legal.operator.vat_id'))
                    · IČ DPH {{ config('legal.operator.vat_id') }}
                @endif
                · Registered in {{ config('legal.operator.register') }}.
            </p>
        </div>
    </div>
</footer>
