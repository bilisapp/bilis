@props(['examples', 'title' => 'What it caught'])
{{ $title }}:
@foreach ($examples as $example)
- {{ \App\Services\Alerting\AlertMessage::exampleLine($example) }}
@endforeach
