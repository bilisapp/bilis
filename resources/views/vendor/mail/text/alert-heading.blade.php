@props(['title', 'labels' => null, 'headline'])
{{ $title }}
@if ($labels)
{{ $labels }}
@endif

{{ $headline }}
