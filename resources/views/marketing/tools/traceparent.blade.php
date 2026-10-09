@php
    /** @var \App\Services\Tools\Traceparent $parsed */
@endphp

<x-tools.page route="tools.traceparent"
              title="Traceparent decoder & generator"
              description="Decode and validate a W3C Trace Context traceparent header — version, trace id, parent span id, sampled flag — or generate a fresh valid one."
              heading="Traceparent decoder & generator"
              intro="Paste a traceparent header to see its trace id, parent span id and flags, and whether a strict receiver would accept it. Or generate a fresh, valid one to test with.">
    <form method="GET"
          action="{{ route('tools.traceparent') }}"
          data-tool-form
          class="grid gap-6">
        <div class="grid gap-2">
            <label for="traceparent-value"
                   class="text-sm font-medium">traceparent</label>
            <input id="traceparent-value"
                   name="value"
                   type="text"
                   autocomplete="off"
                   spellcheck="false"
                   placeholder="00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"
                   value="{{ $generated ? '' : $value }}"
                   class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm">
            <div class="flex flex-wrap gap-2">
                <button type="submit"
                        data-tool-submit
                        class="rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                    Decode
                </button>
                <button type="submit"
                        name="generate"
                        value="1"
                        class="rounded-md border border-border px-5 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                    Generate a new one
                </button>
            </div>
        </div>

        <div data-tool-result
             aria-live="polite"
             class="grid gap-4">
            @if ($parsed->isParsed())
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-background px-4 py-3">
                    <code id="traceparent-header"
                          class="min-w-0 font-mono text-sm break-all">{{ $parsed->header() }}</code>
                    <button type="button"
                            data-copy="traceparent-header"
                            hidden
                            class="ml-auto shrink-0 rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground">
                        <span data-copy-idle>Copy</span>
                        <span data-copy-done
                              hidden
                              class="text-severity-debug">Copied</span>
                    </button>
                </div>

                @if ($generated)
                    <p class="text-sm text-muted-foreground"
                       data-test="traceparent-generated">A fresh header with random ids, sampled. Generate again for another.</p>
                @elseif ($parsed->isValid())
                    <p class="text-sm text-severity-debug"
                       data-test="traceparent-valid">Valid. A W3C-compliant receiver will continue this trace.</p>
                @endif

                <dl class="grid gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-2">
                    @foreach ([
                        ['Version', $parsed->version, $parsed->version === '00' ? 'The only version defined so far.' : 'A future version: read the first four fields, ignore the rest.'],
                        ['Flags', $parsed->flags, 'sampled '.($parsed->isSampled() ? 'on' : 'off').' · random '.($parsed->isRandom() ? 'on' : 'off').($parsed->unknownFlagBits() ? ' · unknown bits set' : '')],
                        ['Trace id', $parsed->traceId, '16 bytes, shared by every span in the trace.'],
                        ['Parent id', $parsed->parentId, '8 bytes: the span id of the caller.'],
                    ] as [$label, $field, $note])
                        <div class="bg-background p-4">
                            <dt class="text-xs text-muted-foreground">{{ $label }}</dt>
                            <dd class="mt-1 font-mono text-sm break-all">{{ $field }}</dd>
                            <dd class="mt-1 text-xs text-muted-foreground">{{ $note }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if ($parsed->errors !== [])
                <ul class="grid gap-2 text-sm"
                    data-test="traceparent-errors">
                    @foreach ($parsed->errors as $error)
                        <li class="rounded-lg border border-border bg-background px-4 py-3 text-severity-error">{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </form>

    <x-slot:explainer>
        <h2>What a traceparent header is</h2>
        <p>
            <code>traceparent</code> is the HTTP header the
            <a href="https://www.w3.org/TR/trace-context-2/">W3C Trace Context</a> standard uses to carry a trace
            from one service to the next. Every OpenTelemetry SDK writes it on outgoing requests and reads it on
            incoming ones, which is how a span in one service becomes the child of a span in another.
        </p>
        <pre><code>traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
             │  │                                │                │
             │  trace id (32 hex)                parent id (16)   flags
             version</code></pre>

        <h2>The four fields</h2>
        <ul>
            <li><strong>version</strong> — two hex digits, <code>00</code> today. <code>ff</code> is forbidden.</li>
            <li><strong>trace id</strong> — 16 bytes as 32 lowercase hex characters, the same for every span in the trace. All zeroes is invalid.</li>
            <li><strong>parent id</strong> — 8 bytes as 16 hex characters: the span id of the span that made the call. All zeroes is invalid.</li>
            <li><strong>trace flags</strong> — one byte. Bit 0 (<code>01</code>) is <em>sampled</em>: the caller may have recorded this trace. Level 2 adds bit 1 (<code>02</code>), <em>random</em>: the trace id's right-most seven bytes are random, so it can be used for consistent sampling.</li>
        </ul>

        <h2>Why a header gets dropped</h2>
        <p>
            A receiver that finds the header malformed must ignore it and start a new trace — so a bad header never
            errors, it silently splits one request into two traces. The usual causes are uppercase hex (the spec
            allows only lowercase), a trace id padded or truncated to the wrong length, an all-zero id from an
            uninitialised context, or a proxy that rewrites the header. The decoder above names which one it is.
        </p>

        <h2>Following the trace</h2>
        <p>
            The trace id is the key to everything the request touched. In <a href="{{ route('home') }}">Bilis</a>,
            the same id links a log line to its span and opens the whole waterfall, across every service that
            passed the header on. See <a href="{{ route('docs.index') }}">the docs</a> for sending traces over OTLP.
        </p>
    </x-slot:explainer>
</x-tools.page>
