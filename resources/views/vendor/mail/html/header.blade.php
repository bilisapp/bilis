@props(['url'])
<tr>
<td class="header">
<table class="header-inner" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('apple-touch-icon.png') }}" class="logo" width="28" height="28" alt=""><span class="wordmark">{{ trim($slot) !== '' ? trim(strip_tags($slot)) : config('app.name') }}</span>
</a>
</td>
</tr>
</table>
</td>
</tr>
