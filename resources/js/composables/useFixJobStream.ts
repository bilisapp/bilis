import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, shallowRef } from 'vue';
import type { Ref } from 'vue';
import type { FixJobEvent } from '@/types';

/** Where the viewer is with respect to the running job. */
export type FixJobStreamStatus =
    'idle' | 'connecting' | 'live' | 'reconnecting' | 'unavailable';

export type UseFixJobStreamOptions = {
    /** The browser-facing Ayos endpoint for this job. */
    streamUrl: string;
    /** The Bilis endpoint that mints a fresh, job-scoped token. */
    tokenUrl: string;
};

export type UseFixJobStreamReturn = {
    events: Ref<FixJobEvent[]>;
    status: Ref<FixJobStreamStatus>;
    start: () => void;
    stop: () => void;
};

/** How long to wait before the nth reconnect attempt, in milliseconds. */
const BACKOFF_MS = [1_000, 2_000, 5_000, 10_000, 30_000];

const SSE_EVENT_TYPES = [
    'phase',
    'agent_message',
    'tool_call',
    'tool_result',
    'test_output',
    'error',
    'done',
] as const;

/**
 * Watch a running fix job's event stream, live, straight from Ayos.
 *
 * The connection is the one thing the browser is allowed to open to Ayos —
 * everything else (cancel, the artifact) goes the long way through Laravel.
 * Authorisation is a short-lived, job-scoped Ed25519 token minted per connect;
 * Ayos checks `exp` *at connect time only*, so a live connection is never cut
 * for an expired token and every reconnect simply mints a new one.
 *
 * Reconnects back off, and a run of failures resolves to `unavailable` rather
 * than hammering a service that is plainly down. The persisted transcript on
 * the page stays visible throughout: a dead stream degrades the live view, it
 * never empties it.
 */
export function useFixJobStream(
    options: UseFixJobStreamOptions,
    initialEvents: FixJobEvent[] = [],
): UseFixJobStreamReturn {
    const events = ref<FixJobEvent[]>([...initialEvents]);
    const status = ref<FixJobStreamStatus>('idle');
    const source = shallowRef<EventSource | WebSocket | null>(null);

    const http = useHttp();

    const seen = new Set<number>(
        initialEvents
            .map((event) => event.seq)
            .filter((seq): seq is number => typeof seq === 'number'),
    );

    let attempt = 0;
    let retryTimer: ReturnType<typeof setTimeout> | null = null;
    let stopped = false;

    function closeSource() {
        const current = source.value;
        source.value = null;

        if (current instanceof WebSocket) {
            current.onclose = null;
            current.onerror = null;
            current.close();

            return;
        }

        current?.close();
    }

    function streamKey(event: FixJobEvent): string | null {
        const key = event.data?.stream_id;

        return typeof key === 'string' && key !== '' ? key : null;
    }

    /**
     * Append or merge an event. Durable events are deduped by Ayos's monotonic
     * `seq`; live-only agentOS deltas have no sequence, so they replace the
     * current row with the same `stream_id` until the committed row arrives.
     */
    function append(payload: string) {
        let event: FixJobEvent;

        try {
            event = JSON.parse(payload) as FixJobEvent;
        } catch {
            return;
        }

        // Ayos also uses the `error` SSE for stream-level notices — most
        // commonly {"error": "unknown job"} while the job is still queued on
        // our side. Those carry no event `type` and are not part of the
        // transcript; rendering them would add a blank row per reconnect.
        // The server closes the stream after sending one, so the normal
        // retry/backoff picks it up from there.
        if (!(SSE_EVENT_TYPES as readonly string[]).includes(event?.type)) {
            return;
        }

        const key = streamKey(event);
        const replaceEphemeral = () => {
            if (!key) {
                return false;
            }

            const index = events.value.findIndex(
                (candidate) =>
                    streamKey(candidate) === key &&
                    (candidate.durability === 'ephemeral' ||
                        typeof candidate.seq !== 'number'),
            );

            if (index === -1) {
                return false;
            }

            const next = [...events.value];
            next[index] = event;
            events.value = next;

            return true;
        };

        if (typeof event?.seq !== 'number') {
            if (!replaceEphemeral()) {
                events.value = [...events.value, event];
            }

            return;
        }

        if (seen.has(event.seq)) {
            return;
        }

        seen.add(event.seq);

        if (!replaceEphemeral()) {
            events.value = [...events.value, event];
        }

        // `done` is the transcript's last word: Ayos closes the stream after
        // it, and Bilis refuses tokens for a finished job (403). Reconnecting
        // would only hammer both, so the stream ends with the job.
        if (event.type === 'done') {
            stop();
        }
    }

    function scheduleRetry() {
        if (stopped) {
            return;
        }

        if (attempt >= BACKOFF_MS.length) {
            status.value = 'unavailable';

            return;
        }

        status.value = 'reconnecting';
        retryTimer = setTimeout(() => void connect(), BACKOFF_MS[attempt]);
        attempt += 1;
    }

    function onOpen() {
        attempt = 0;
        status.value = 'live';
    }

    function onFailure(event?: Event) {
        const data = (event as MessageEvent | undefined)?.data;

        // Ayos uses a named `error` SSE for job errors. That is data, not a
        // broken EventSource transport.
        if (typeof data === 'string' && data !== '') {
            let parsed: unknown = null;

            try {
                parsed = JSON.parse(data);
            } catch {
                // Not JSON; fall through to append, which ignores it too.
            }

            // A stream-level notice — {"error": "unknown job"} while the job
            // is still queued on our side — proves Ayos is reachable, so it
            // must not count towards giving the stream up: hold at the top of
            // the backoff ladder instead. The server closes the stream after
            // sending one; the transport error that follows retries.
            if (
                parsed !== null &&
                typeof parsed === 'object' &&
                !('type' in parsed)
            ) {
                attempt = Math.min(attempt, BACKOFF_MS.length - 1);

                return;
            }

            append(data);

            return;
        }

        closeSource();
        scheduleRetry();
    }

    async function connect() {
        if (stopped) {
            return;
        }

        status.value = status.value === 'live' ? 'reconnecting' : 'connecting';

        let token: { token: string; stream_url: string };

        try {
            token = (await http.submit({
                url: options.tokenUrl,
                method: 'post',
            })) as { token: string; stream_url: string };
        } catch {
            scheduleRetry();

            return;
        }

        if (stopped || !token?.token) {
            return;
        }

        const url = new URL(token.stream_url || options.streamUrl);
        url.searchParams.set('token', token.token);

        closeSource();

        // Ayos may expose the stream as SSE or as a WebSocket; the scheme it
        // hands back decides, so neither side has to be told twice.
        if (url.protocol === 'ws:' || url.protocol === 'wss:') {
            const socket = new WebSocket(url.toString());

            socket.onopen = onOpen;
            socket.onmessage = (message) => append(String(message.data));
            socket.onerror = onFailure;
            socket.onclose = onFailure;
            source.value = socket;

            return;
        }

        const stream = new EventSource(url.toString());

        stream.onopen = onOpen;
        stream.onmessage = (message) => append(String(message.data));
        for (const type of SSE_EVENT_TYPES) {
            stream.addEventListener(type, (message) =>
                append(String((message as MessageEvent).data)),
            );
        }
        stream.onerror = onFailure;
        source.value = stream;
    }

    function start() {
        // A transcript that already ends with `done` has nothing left to
        // stream, and the token endpoint would refuse the job anyway.
        if (events.value.some((event) => event.type === 'done')) {
            return;
        }

        stopped = false;
        attempt = 0;
        void connect();
    }

    function stop() {
        stopped = true;

        if (retryTimer !== null) {
            clearTimeout(retryTimer);
            retryTimer = null;
        }

        closeSource();
        status.value = 'idle';
    }

    onBeforeUnmount(stop);

    return { events, status, start, stop };
}
