<x-mail::message>
# {{ $contactMessage->topic->label() }}

**{{ $contactMessage->name }}** &lt;{{ $contactMessage->email }}&gt; wrote:

<x-mail::panel>
{{ $contactMessage->message }}
</x-mail::panel>

@if ($contactMessage->user && $contactMessage->team)
Signed in as {{ $contactMessage->user->email }}, looking at the **{{ $contactMessage->team->name }}** team.
@elseif ($contactMessage->user)
Signed in as {{ $contactMessage->user->email }}.
@else
Not signed in.
@endif

Reply to this email to answer them directly.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
