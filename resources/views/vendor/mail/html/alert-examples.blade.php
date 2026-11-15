@props(['examples', 'title' => 'What it caught'])
<p class="alert-section">{{ $title }}</p>
<table class="alert-examples" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($examples as $example)
<tr>
<td class="alert-example">
<div class="alert-example-title">{{ $example->title }}</div>
@if ($example->service !== null || $example->detail !== null)
<div class="alert-example-meta">{{ implode(' · ', array_filter([$example->service, $example->detail])) }}</div>
@endif
</td>
<td class="alert-example-count">{{ number_format($example->count) }}×</td>
</tr>
@endforeach
</table>
