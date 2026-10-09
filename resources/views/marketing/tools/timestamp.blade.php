@php
    /**
     * @var \App\Services\Tools\TimestampConversion $conversion
     * @var list<string> $timezones
     * @var array<string, string> $units
     */

    $unitNames = ['s' => 'seconds', 'ms' => 'milliseconds', 'us' => 'microseconds', 'ns' => 'nanoseconds', 'date' => 'a date', 'now' => 'now'];
@endphp

<x-tools.page route="tools.timestamp"
              title="Unix timestamp converter (s, ms, µs, ns)"
              description="Convert Unix timestamps in seconds, milliseconds, microseconds or nanoseconds to dates and back, with OTLP timeUnixNano and ClickHouse DateTime64 formats."
              heading="Unix timestamp converter, down to the nanosecond"
              intro="Paste an epoch timestamp in any unit — the unit is detected from its length — or a date, and get it back in every unit at once. Nanoseconds survive the round trip, so OpenTelemetry's timeUnixNano values convert exactly.">
    <form method="GET"
          action="{{ route('tools.timestamp') }}"
          data-tool-form
          class="grid gap-6">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem_14rem]">
            <div class="grid content-start gap-2">
                <label for="timestamp-value"
                       class="text-sm font-medium">Timestamp or date</label>
                <input id="timestamp-value"
                       name="value"
                       type="text"
                       autocomplete="off"
                       spellcheck="false"
                       placeholder="1760020200000000000, 2026-10-09T14:30:00Z — empty for now"
                       value="{{ $conversion->input }}"
                       class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm">
            </div>
            <div class="grid content-start gap-2">
                <label for="timestamp-unit"
                       class="text-sm font-medium">Unit</label>
                <select id="timestamp-unit"
                        name="unit"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                    @foreach ($units as $key => $label)
                        <option value="{{ $key }}"
                                @selected(request('unit', 'auto') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid content-start gap-2">
                <label for="timestamp-tz"
                       class="text-sm font-medium">Time zone</label>
                <select id="timestamp-tz"
                        name="tz"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                    @foreach ($timezones as $zone)
                        <option value="{{ $zone }}"
                                @selected($conversion->timezone->getName() === $zone)>{{ $zone }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <button type="submit"
                    data-tool-submit
                    class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                Convert
            </button>
        </div>

        <div data-tool-result
             aria-live="polite">
            @if ($conversion->isValid())
                <p class="mb-4 text-sm text-muted-foreground"
                   data-test="timestamp-detected">
                    @if ($conversion->detectedUnit === 'now')
                        The current time, when this page was rendered.
                    @else
                        Read as {{ $unitNames[$conversion->detectedUnit] ?? $conversion->detectedUnit }} — {{ $conversion->relative() }}.
                    @endif
                </p>

                <dl class="divide-y divide-border rounded-xl border border-border bg-background">
                    @foreach ([
                        ['iso', 'ISO 8601, UTC', $conversion->iso()],
                        ['zoned', 'ISO 8601, '.$conversion->timezone->getName(), $conversion->zoned()],
                        ['rfc', 'RFC 2822', $conversion->rfc2822()],
                        ['s', 'Seconds', $conversion->seconds()],
                        ['ms', 'Milliseconds', $conversion->milliseconds()],
                        ['us', 'Microseconds', $conversion->microseconds()],
                        ['ns', 'Nanoseconds', $conversion->nanosecondsString()],
                        ['otlp', 'OTLP/JSON', '"timeUnixNano": "'.$conversion->nanosecondsString().'"'],
                        ['clickhouse', "ClickHouse DateTime64(9, 'UTC')", "'".$conversion->clickHouse()."'"],
                    ] as [$key, $label, $formatted])
                        @continue($key === 'zoned' && $conversion->timezone->getName() === 'UTC')
                        <div class="grid gap-1 px-4 py-3 sm:grid-cols-[14rem_minmax(0,1fr)_auto] sm:items-center sm:gap-4">
                            <dt class="text-xs text-muted-foreground">{{ $label }}</dt>
                            <dd id="timestamp-{{ $key }}"
                                class="font-mono text-sm break-all"
                                data-test="timestamp-{{ $key }}">{{ $formatted }}</dd>
                            <dd>
                                <button type="button"
                                        data-copy="timestamp-{{ $key }}"
                                        hidden
                                        class="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground">
                                    <span data-copy-idle>Copy</span>
                                    <span data-copy-done
                                          hidden
                                          class="text-severity-debug">Copied</span>
                                </button>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="rounded-lg border border-border bg-background px-4 py-3 text-sm text-severity-error"
                   data-test="timestamp-error">{{ $conversion->error }}</p>
            @endif
        </div>
    </form>

    <x-slot:explainer>
        <h2>Which unit is my timestamp in?</h2>
        <p>
            Count the digits. A Unix timestamp for any date between 2001 and 2286 has 10 digits in seconds, 13 in
            milliseconds, 16 in microseconds and 19 in nanoseconds. The converter uses the same rule — up to 11
            digits is seconds, 12–14 milliseconds, 15–17 microseconds, anything longer nanoseconds — and you can
            override it with the unit picker when a value is ambiguous.
        </p>
        <table>
            <thead>
                <tr><th>Unit</th><th>Digits today</th><th>Where you meet it</th></tr>
            </thead>
            <tbody>
                <tr><td>Seconds</td><td>10</td><td><code>date +%s</code>, PHP <code>time()</code>, Python <code>int(time.time())</code>, JWT <code>exp</code></td></tr>
                <tr><td>Milliseconds</td><td>13</td><td>JavaScript <code>Date.now()</code>, Java <code>System.currentTimeMillis()</code></td></tr>
                <tr><td>Microseconds</td><td>16</td><td>PostgreSQL internals, Python <code>time.time_ns() // 1000</code></td></tr>
                <tr><td>Nanoseconds</td><td>19</td><td>OpenTelemetry <code>timeUnixNano</code>, Go <code>UnixNano()</code>, <code>date +%s%N</code></td></tr>
            </tbody>
        </table>

        <h2>Nanoseconds in OpenTelemetry</h2>
        <p>
            OTLP carries every time as <code>fixed64</code> nanoseconds since the epoch — <code>timeUnixNano</code>
            on a log record, <code>startTimeUnixNano</code> and <code>endTimeUnixNano</code> on a span. In OTLP/JSON
            the value is written as a <em>string</em>, because a 19-digit integer does not fit in a JavaScript
            number: <code>1760020200123456789</code> parsed as a double comes back as <code>1760020200123456800</code>.
            This converter never passes the value through a float, so the last digits survive. The field is
            unsigned, so dates before 1970 cannot be expressed, and the largest value a signed 64-bit integer holds
            is 2262-04-11.
        </p>

        <h2>Storing them</h2>
        <p>
            ClickHouse keeps nanosecond time in <code>DateTime64(9)</code>, which is what the OpenTelemetry
            exporter's tables use and what <a href="{{ route('home') }}">Bilis</a> stores logs, spans and metrics
            in. The literal on the last line above can be pasted straight into a query's <code>WHERE</code>.
        </p>
    </x-slot:explainer>
</x-tools.page>
