@props(['state', 'severity' => null])
{{ strtoupper(match ($state) { 'error' => 'Rule error', 'new' => 'New issue', 'regressed' => 'Regressed issue', default => $state }) }}{{ $severity ? ' · '.ucfirst($severity) : '' }}
