@props(['sections' => [], 'current' => null])

{{-- The grouped sidebar nav, shared by the desktop rail and the mobile disclosure. --}}
<nav aria-label="Documentation" {{ $attributes->merge(['class' => 'space-y-6 text-sm']) }}>
    @foreach ($sections as $section)
        <div>
            <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ $section->title }}</p>

            <ul class="mt-2 space-y-0.5">
                @foreach ($section->pages as $page)
                    <li>
                        <a href="{{ $page->url() }}"
                           @if ($page->is($current)) aria-current="page" @endif
                           @class([
                               'block rounded-md px-2.5 py-1.5 transition-colors',
                               'bg-secondary font-medium text-secondary-foreground' => $page->is($current),
                               'text-muted-foreground hover:bg-accent hover:text-accent-foreground' => ! $page->is($current),
                           ])>
                            {{ $page->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
