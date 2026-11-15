@props(['state', 'severity' => null])
@php
    $label = match ($state) {
        'firing' => 'Firing',
        'resolved' => 'Resolved',
        'error' => 'Rule error',
        'new' => 'New issue',
        'regressed' => 'Regressed issue',
        default => 'Test',
    };
    $dot = match ($state) {
        'firing', 'new', 'regressed' => 'alert-dot-error',
        'error' => 'alert-dot-ink',
        default => 'alert-dot-hollow',
    };
@endphp
<p class="alert-state"><span class="alert-dot {{ $dot }}"></span>{{ $label }}@if ($severity)<span class="alert-state-severity"> &nbsp;·&nbsp; {{ ucfirst($severity) }}</span>@endif</p>
