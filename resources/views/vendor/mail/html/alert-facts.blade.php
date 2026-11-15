@props(['facts', 'mono' => []])
<table class="alert-facts" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($facts as $label => $value)
<tr>
<td class="alert-fact-label">{{ $label }}</td>
<td class="alert-fact-value{{ in_array($label, $mono, true) ? ' alert-mono' : '' }}">{{ $value }}</td>
</tr>
@endforeach
</table>
