{{-- The one public footer, shared by every surface a logged-out visitor can
     reach. The styleguide lives here rather than in the header: it is a
     resource for people building on Bilis, not a sales surface. --}}
@props(['wide' => false])

<footer {{ $attributes->merge(['class' => 'border-t border-border']) }}>
    <div @class([
        'mx-auto flex flex-col gap-4 px-4 py-8 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6',
        'max-w-7xl' => $wide,
        'max-w-5xl' => ! $wide,
    ])>
        <div class="flex items-center gap-2.5">
            <img src="/logo-mark.svg"
                 alt=""
                 class="h-5 w-auto"
                 width="20790"
                 height="4080">
            <span>{{ config('app.name', 'Bilis') }} — self-hosted logs and traces.</span>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <a href="{{ config('bilis.github_url') }}"
               class="flex items-center gap-1.5 transition-colors hover:text-foreground"
               target="_blank"
               rel="noopener noreferrer">
                <x-icons.github class="size-4" />
                Source on GitHub
            </a>
            <a href="{{ route('features') }}"
               class="transition-colors hover:text-foreground">Features</a>
            <a href="{{ route('docs.index') }}"
               class="transition-colors hover:text-foreground">Docs</a>
            <a href="{{ route('blog.index') }}"
               class="transition-colors hover:text-foreground">Blog</a>
            <a href="{{ route('styleguide') }}"
               class="transition-colors hover:text-foreground">Styleguide</a>
            <a href="{{ route('terms') }}"
               class="transition-colors hover:text-foreground">Terms</a>
            <a href="{{ route('privacy') }}"
               class="transition-colors hover:text-foreground">Privacy</a>
            <a href="{{ config('bilis.github_url') }}/blob/main/SECURITY.md"
               class="transition-colors hover:text-foreground"
               target="_blank"
               rel="noopener noreferrer">Security</a>
        </div>
    </div>

    {{-- Operator identification, as Slovak and EU rules require on a business website. --}}
    <div class="border-t border-border">
        <div @class([
            'mx-auto px-4 py-6 text-xs leading-relaxed text-muted-foreground sm:px-6',
            'max-w-7xl' => $wide,
            'max-w-5xl' => ! $wide,
        ])>
            <p>
                {{ config('app.name', 'Bilis') }} is operated by
                <span class="text-foreground">{{ config('legal.operator.name') }}</span>,
                {{ config('legal.operator.address') }}, {{ config('legal.operator.country') }}.
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
