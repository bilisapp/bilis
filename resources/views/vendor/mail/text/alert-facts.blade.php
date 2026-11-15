@props(['facts', 'mono' => []])
@foreach ($facts as $label => $value)
{{ $label }}: {{ $value }}
@endforeach
