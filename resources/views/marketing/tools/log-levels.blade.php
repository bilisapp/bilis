@php
    /**
     * @var array<string, array{label: string, levels: list<array{name: string, value: int|string, severityNumber: int, severityText: string, bucket: \App\Services\Logs\SeverityLevel}>}> $systems
     * @var array{query: string, asText: array|null, asNumber: array|null, native: list<array>} $lookup
     */

    // Spelled out in full so the stylesheet build can see every class.
    $tone = [
        'trace' => 'text-severity-trace',
        'debug' => 'text-severity-debug',
        'info' => 'text-severity-info',
        'warn' => 'text-severity-warn',
        'error' => 'text-severity-error',
        'fatal' => 'text-severity-fatal',
    ];

    $buckets = [
        ['TRACE', '1–4', 'Step-by-step detail, usually off in production.'],
        ['DEBUG', '5–8', 'Diagnostics a developer reads while chasing a bug.'],
        ['INFO', '9–12', 'Normal events worth a record: a request served, a job done.'],
        ['WARN', '13–16', 'Something unexpected that the system recovered from.'],
        ['ERROR', '17–20', 'An operation failed; someone may need to look.'],
        ['FATAL', '21–24', 'The process or service cannot continue.'],
    ];
@endphp

<x-tools.page route="tools.log-levels"
              title="Log levels explained and mapped"
              description="Log levels across syslog, PSR-3, Python, Log4j, Go slog, pino, .NET and Ruby — numbers, names, and where each lands on the OpenTelemetry severity scale."
              heading="Log levels, mapped across languages"
              intro="Syslog counts down from 7, Python counts up in tens, Log4j counts down in hundreds and Go starts below zero. Look up any level name or number, or read the whole table, and see where it lands on the one scale OpenTelemetry uses for all of them.">
    <form method="GET"
          action="{{ route('tools.log-levels') }}"
          data-tool-form
          class="grid gap-6">
        <div class="grid max-w-xl gap-2">
            <label for="level-query"
                   class="text-sm font-medium">Level name or number</label>
            <div class="flex gap-2">
                <input id="level-query"
                       name="level"
                       type="text"
                       autocomplete="off"
                       spellcheck="false"
                       placeholder="warning, CRIT, 40, 17…"
                       value="{{ $lookup['query'] }}"
                       class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm">
                <button type="submit"
                        data-tool-submit
                        class="shrink-0 rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                    Look up
                </button>
            </div>
        </div>

        <div data-tool-result
             aria-live="polite">
            @if ($lookup['query'] !== '')
                @if (! $lookup['asText'] && ! $lookup['asNumber'] && $lookup['native'] === [])
                    <p class="text-sm text-muted-foreground"
                       data-test="level-none">
                        Nothing calls a level <span class="font-mono text-foreground">{{ $lookup['query'] }}</span>.
                        A record sent with an unrecognised severity text and no number is stored as unspecified
                        (severity 0) — send <code class="font-mono">SeverityNumber</code> to be sure.
                    </p>
                @else
                    <ul class="divide-y divide-border rounded-xl border border-border bg-background text-sm">
                        @if ($lookup['asText'])
                            <li class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3">
                                <span>As a severity text, <span class="font-mono">{{ $lookup['query'] }}</span> reads as</span>
                                <span class="font-mono {{ $tone[$lookup['asText']['bucket']->value] }}">{{ $lookup['asText']['severityText'] }} · {{ $lookup['asText']['severityNumber'] }}</span>
                            </li>
                        @endif
                        @if ($lookup['asNumber'])
                            <li class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3">
                                <span>As an OpenTelemetry SeverityNumber, {{ $lookup['query'] }} is</span>
                                <span class="font-mono {{ $tone[$lookup['asNumber']['bucket']->value] }}">{{ $lookup['asNumber']['severityText'] }}</span>
                            </li>
                        @endif
                        @foreach ($lookup['native'] as $match)
                            <li class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3">
                                <span>{{ $match['system'] }}: <span class="font-mono">{{ $match['name'] }}</span> = <span class="font-mono">{{ $match['value'] }}</span></span>
                                <span class="font-mono {{ $tone[$match['bucket']->value] }}">{{ $match['severityText'] }} · {{ $match['severityNumber'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    </form>

    <div class="mt-10 grid gap-6 md:grid-cols-2">
        @foreach ($systems as $key => $system)
            <div class="overflow-hidden rounded-xl border border-border bg-background"
                 id="{{ $key }}">
                <h2 class="border-b border-border px-4 py-3 text-sm font-semibold">{{ $system['label'] }}</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Level</th>
                            <th class="px-4 py-2 font-medium">Value</th>
                            <th class="px-4 py-2 text-right font-medium">OpenTelemetry</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border font-mono">
                        @foreach ($system['levels'] as $level)
                            <tr>
                                <td class="px-4 py-2">{{ $level['name'] }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ $level['value'] }}</td>
                                <td class="px-4 py-2 text-right {{ $tone[$level['bucket']->value] }}">{{ $level['severityText'] }} · {{ $level['severityNumber'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <x-slot:explainer>
        <h2>The OpenTelemetry severity scale</h2>
        <p>
            OpenTelemetry gives every log record a <code>SeverityNumber</code> from 1 to 24, in six ranges of four,
            and an optional <code>SeverityText</code> holding whatever the source called the level. Within a range,
            a higher number is more severe — <code>ERROR2</code> (18) is worse than <code>ERROR</code> (17). Zero
            means unspecified.
        </p>
        <table>
            <thead>
                <tr><th>Range</th><th>Numbers</th><th>Meaning</th></tr>
            </thead>
            <tbody>
                @foreach ($buckets as [$name, $range, $meaning])
                    <tr><td><code>{{ $name }}</code></td><td>{{ $range }}</td><td>{{ $meaning }}</td></tr>
                @endforeach
            </tbody>
        </table>

        <h2>Why the native numbers disagree</h2>
        <p>
            Each system picked its own direction and spacing. Syslog (RFC 5424) numbers from 0, Emergency, down to 7,
            Debug, because lower meant more urgent on the wire. Log4j kept that direction in hundreds. Python and
            pino count up in tens, PSR-3 implementations such as Monolog count up in hundreds with half-steps for
            Notice and Alert, and Go's <code>slog</code> puts Info at 0 so that Debug is negative. None of those
            numbers can be compared directly, which is exactly the problem a shared scale solves.
        </p>

        <h2>How the right-hand column is decided</h2>
        <p>
            When a record arrives with only a level <em>name</em>, the name is matched case-insensitively against a
            list of aliases — <code>warning</code> and <code>warn</code> are both 13, <code>critical</code> is 18,
            <code>alert</code> 19, <code>emergency</code> and <code>fatal</code> 21. The column above is computed by
            that same function in <a href="{{ route('home') }}">Bilis</a>'s ingest path, so it is what Bilis
            stores. An SDK that sends <code>SeverityNumber</code> itself always wins: if your exporter maps
            Python's <code>CRITICAL</code> to <code>FATAL</code>, that is what you will see.
        </p>

        <h2>Choosing a level</h2>
        <p>
            Log at <strong>ERROR</strong> when something failed and the failure is not handled further up;
            <strong>WARN</strong> when it was handled but should not keep happening; <strong>INFO</strong> for the
            events you would want in a timeline of an incident; <strong>DEBUG</strong> for what you need only while
            fixing one. Most alerting and most noise both come from getting the first two wrong.
        </p>
    </x-slot:explainer>
</x-tools.page>
