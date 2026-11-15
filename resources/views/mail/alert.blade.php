{{--
    One alert event (App\Notifications\AlertNotification::toMail): its state,
    what happened in a sentence, the facts, what the window caught, and where
    to look. Every piece is an HTML component — the body goes through the
    Markdown parser, which leaves an HTML block alone, so a log message is
    printed as text and never read as Markdown.
--}}
<x-mail::message :footnote="$footnote">
<x-mail::alert-state :state="$alert->state" :severity="$alert->severity?->value" />

<x-mail::alert-heading :title="$heading" :labels="$labels" :headline="$alert->headline" />

@if ($facts !== [])
<x-mail::alert-facts :facts="$facts" :mono="['Window']" />
@endif

@if ($alert->examples !== [])
<x-mail::alert-examples :examples="$alert->examples" />
@endif

@if ($alert->url !== null)
<x-mail::button :url="$alert->url" align="left">
{{ $actionText }}
</x-mail::button>
@endif

@if ($ruleUrl !== null)
<x-mail::alert-link :url="$ruleUrl">
See the rule live
</x-mail::alert-link>
@endif

@if ($alert->footer !== null && $editUrl !== null)
<x-slot:subcopy>
Bilis set this alert up automatically. [Tune it or turn it off]({{ $editUrl }}).
</x-slot:subcopy>
@endif
</x-mail::message>
