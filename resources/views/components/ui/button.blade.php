{{-- A link that acts as a button, in the app's three variants: primary is ink,
     outline is a hairline, ghost is text. Never a coloured fill. --}}
@props(['href', 'variant' => 'primary', 'size' => 'default', 'external' => false])

<a href="{{ $href }}"
   @if ($external) target="_blank" rel="noopener noreferrer" @endif
   {{ $attributes->class([
       'inline-flex shrink-0 items-center justify-center gap-2 rounded-md font-medium whitespace-nowrap transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
       'h-10 px-5 text-sm' => $size === 'lg',
       'h-9 px-4 text-sm' => $size === 'default',
       'bg-primary text-primary-foreground hover:bg-primary/90' => $variant === 'primary',
       'border border-input bg-transparent text-foreground hover:bg-accent hover:text-accent-foreground' => $variant === 'outline',
       'text-muted-foreground hover:bg-accent hover:text-accent-foreground' => $variant === 'ghost',
   ]) }}>{{ $slot }}</a>
