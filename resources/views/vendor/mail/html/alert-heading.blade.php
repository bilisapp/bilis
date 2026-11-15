@props(['title', 'labels' => null, 'headline'])
<h1>{{ $title }}</h1>
@if ($labels)
<p class="alert-labels">{{ $labels }}</p>
@endif
<p class="alert-headline">{{ $headline }}</p>
