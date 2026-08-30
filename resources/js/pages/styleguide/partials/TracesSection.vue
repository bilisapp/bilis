<script setup lang="ts">
import { ref } from 'vue';
import ServiceLatencySection from '@/components/ServiceLatencySection.vue';
import SpanAttributes from '@/components/SpanAttributes.vue';
import SpanDetailPanel from '@/components/SpanDetailPanel.vue';
import SpanNamingToggle from '@/components/SpanNamingToggle.vue';
import SpanWaterfall from '@/components/SpanWaterfall.vue';
import TraceFact from '@/components/TraceFact.vue';
import TraceListRow from '@/components/TraceListRow.vue';
import TracePanel from '@/components/TracePanel.vue';
import TracesTabs from '@/components/TracesTabs.vue';
import TracesToolbar from '@/components/TracesToolbar.vue';
import { serviceColours } from '@/lib/traces';
import {
    DEMO_AGENT_SPANS,
    DEMO_SERVICE_LATENCY,
    DEMO_SPANS,
    DEMO_TRACE_PANEL,
    DEMO_TRACE_PANEL_EXPIRED,
    DEMO_TRACES,
} from '@/pages/styleguide/data';
import DemoBlock from './DemoBlock.vue';

const selectedSpanId = ref(DEMO_SPANS[1].spanId);
const selectedAgentSpanId = ref(DEMO_AGENT_SPANS[2].spanId);

const colours = serviceColours(DEMO_SPANS);

const demoProject = ref<string | null>(null);
const demoService = ref<string | null>(null);
const demoErrors = ref(false);
const demoMinDuration = ref<number | null>(null);
const demoLive = ref(true);
</script>

<template>
    <div class="space-y-6">
        <DemoBlock
            title="SpanWaterfall"
            description="the trace detail's centrepiece: a fixed Span and Detail column against a timeline whose header doubles as a labelled time axis, with gridlines running continuously behind every row so a bar can be read against a number without a ruler. Ticks land on a 1/2/5 ladder, so the axis says 250ms rather than 237ms. Bars are coloured by service — a service is a data series, so it spends the sanctioned --chart-* palette rather than inventing a colour family, and the legend above is the key that makes those colours mean something. A failed span overrides to severity-error, because 'this broke' has to win over 'this belongs to payments'. Rows carry a disclosure triangle and, once collapsed, a count of the children they are hiding; bar geometry is computed against the full set, so collapsing a subtree never moves a bar that is still on screen. The last row is an orphan — its parent aged out, is still in flight, or fell past the row cap — drawn at the top level with a marker rather than dropped. The first row wears the other marker: 'linked parent' means the span is not really a root, its parent is in another trace and was named through a span link, which a tree cannot draw."
        >
            <div class="overflow-hidden rounded-md border">
                <SpanWaterfall
                    :spans="DEMO_SPANS"
                    :selected-span-id="selectedSpanId"
                    @select="selectedSpanId = $event"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="SpanWaterfall — derived labels"
            description="the same component against an instrumentation that names span types rather than instances. Every span here is called claude_code.tool or claude_code.llm_request and every kind is `internal`: correctly named per OpenTelemetry's own advice, which says span names should be low-cardinality, and collectively unreadable — four hundred identical rows. The identity is in the attributes, so that is where the label comes from. The rules key on published semantic-convention attributes (http.route, db.query.text, gen_ai.tool.name, gen_ai.usage.*) with vendor spellings accepted alongside them, so a span earns a label by being well-described rather than by coming from a sender Bilis has heard of. Label answers 'which one', Detail answers 'what kind' — the second column stays low-cardinality on purpose, because a column earns its width by being scannable. Flip the toggle to Raw to see what the exporter actually sent; the derived label is an interpretation and the reader has to be able to get behind it."
        >
            <div class="overflow-hidden rounded-md border">
                <SpanWaterfall
                    :spans="DEMO_AGENT_SPANS"
                    :selected-span-id="selectedAgentSpanId"
                    @select="selectedAgentSpanId = $event"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="SpanNamingToggle"
            description="the Smart/Raw switch that rides in the waterfall's legend bar, shown here on its own. It is a segmented control rather than a checkbox because the two states are two ways of reading the same data and neither is an 'off'. The choice is module-level and persisted, so every waterfall and detail panel on the page moves together — a panel captioning a row differently from the row itself would be worse than either naming alone."
        >
            <div class="flex items-center gap-3">
                <SpanNamingToggle />
                <SpanNamingToggle compact />
            </div>
        </DemoBlock>

        <DemoBlock
            title="TracePanel"
            description="the trace preview the log viewer opens beside itself when a line carries a TraceId. It is an in-flow column, not an overlay: the stream narrows rather than being covered, stays scrollable, and every row stays clickable so the panel can be swapped from line to line without closing. It reuses SpanWaterfall in `compact` mode — the Detail column drops and the Span column halves, but the timeline, the service colours and the collapse controls are the same component rather than a second implementation. Three of its four states are shown here; the fourth is a skeleton while the request is in flight. 'Go to detail' stays live even when the spans are gone, because the full page explains that case too."
        >
            <div class="grid items-start gap-4 lg:grid-cols-2">
                <div class="flex h-[32rem] flex-col">
                    <TracePanel
                        :trace-id="DEMO_TRACE_PANEL.traceId"
                        team-slug="acme"
                        :result="DEMO_TRACE_PANEL"
                    />
                </div>
                <div class="flex h-[32rem] flex-col">
                    <TracePanel
                        :trace-id="DEMO_TRACE_PANEL_EXPIRED.traceId"
                        team-slug="acme"
                        :result="DEMO_TRACE_PANEL_EXPIRED"
                    />
                </div>
            </div>
        </DemoBlock>

        <DemoBlock
            title="SpanDetailPanel"
            description="what one span carries, beside the waterfall: its facts as a two-column grid, its status message when it failed, and its attributes, events and links behind tabs so a span with two hundred attributes cannot push the rest out of reach. The Links tab is how a span says it belongs with one in another trace — the relationship comes from the link's own attributes (`link.type: parent_of` is what Claude Code writes on every llm_request) — and a link is only offered as a way out when this instance actually holds the trace it names. The second link here is one it does not: naming a trace is not the same as having received it. Attribute keys sit above their values rather than beside them: in a 22rem panel a two-column grid gives every value whatever the longest key leaves behind, so one long attribute name starves the whole list. The 'View logs for this span' link is the reason both signals live in one product — the span already knows its trace and span ids, so the log viewer gets an exact predicate rather than a search."
        >
            <div class="max-w-sm">
                <SpanDetailPanel
                    :span="DEMO_SPANS[1]"
                    team-slug="acme"
                    :colour="colours.get(DEMO_SPANS[1].serviceName)"
                    :linked-traces="{
                        [DEMO_TRACES[1].traceId]: DEMO_TRACES[1],
                    }"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="SpanAttributes"
            description="the span's own record, which arrives as a flat map of stringified values and reads as a wall unless something sorts it. Keys are grouped by what they describe — Outcome first, because it answers the question that opened the panel, then the work, then the machinery, then the paperwork — and Identity and Environment arrive folded, since they are eight of these twenty-five keys and never what a reader came for. The namespace of each key is dimmed so the last segment, which is the part being scanned for, keeps the weight. Values are drawn by what the key declares them to be rather than by guessing at the string: a duration reads 13.78 s, a token count carries separators and tabular-nums, a command or a prompt gets a wrapped block clamped to a few lines, an id recedes. Colour appears on exactly one thing — an attribute stating a failure — and it borrows the span-failure token rather than introducing a family. Past a dozen keys a filter appears, and a filter opens every group, because a hit hidden inside a folded one is indistinguishable from no hit."
        >
            <div class="flex flex-wrap items-start gap-4">
                <div class="w-full max-w-sm rounded-lg border bg-card p-4">
                    <SpanAttributes
                        :attributes="DEMO_AGENT_SPANS[1].attributes"
                        :reset-key="DEMO_AGENT_SPANS[1].spanId"
                    />
                </div>

                <!-- The other two value treatments: a wrapped, clamped command
                     block, and the one row allowed a colour. -->
                <div class="w-full max-w-sm rounded-lg border bg-card p-4">
                    <SpanAttributes
                        :attributes="DEMO_AGENT_SPANS[2].attributes"
                        :reset-key="DEMO_AGENT_SPANS[2].spanId"
                    />
                    <div class="mt-4 border-t pt-4">
                        <SpanAttributes
                            :attributes="DEMO_AGENT_SPANS[4].attributes"
                            :reset-key="DEMO_AGENT_SPANS[4].spanId"
                        />
                    </div>
                </div>
            </div>
        </DemoBlock>

        <DemoBlock
            title="TraceFact"
            description="one cell of the trace header's fact grid: a quiet label over a loud value, optionally with a second muted line and a copy button. The contrast between label and value is what separates the facts at this density — a box around each one would only add lines to count. Used for the whole header and for the span panel's grid, which is why the two read as the same surface."
        >
            <dl
                class="grid max-w-2xl grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4"
            >
                <TraceFact label="Status" value="ok" />
                <TraceFact label="Duration" value="252 ms" />
                <TraceFact label="Spans" value="14" detail="2 errors" />
                <TraceFact label="Service" value="checkout" detail="v2.4.1" />
                <TraceFact
                    label="Trace ID"
                    value="5b8efff798038103d269b633813fc60c"
                    mono
                    copyable
                    class="col-span-2"
                />
            </dl>
        </DemoBlock>

        <DemoBlock
            title="TraceListRow"
            description="one row per trace, reading from trace_summary rather than from the spans themselves. A trace containing an error takes the severity-error token — the same one an error log line wears — while the duration column carries the magnitude ramp, so “this broke” and “this was slow” are two different facts wearing two different families and a row can say both at once. Everything else stays achromatic. The last row is a trace whose spans have passed the 30-day retention window while its 90-day summary survived: it keeps its place in the list, loses its link, and says why. Every live row links to the waterfall with the trace's own start time in the query string, which is what keeps the span lookup bounded to seconds instead of scanning a month."
        >
            <div class="overflow-hidden rounded-md border">
                <TraceListRow
                    v-for="trace in DEMO_TRACES"
                    :key="trace.traceId"
                    :trace="trace"
                    team-slug="acme"
                    :expired="trace.traceId === DEMO_TRACES[2].traceId"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="ServiceLatencySection"
            description="p95 and p99 per service over the selected window, drawn through ChartCanvas like every other chart. The two series take the first two chart slots rather than borrowing a severity hue: latency is not a log level. The p95 and p99 columns carry the magnitude ramp on absolute thresholds, so a slow service surfaces before the table is read and 241ms means the same thing here as it does in the trace list. The error-rate column is the one place the severity-error token appears here, because a non-zero error rate is the same kind of fact a failed span is — and the two families never collide, since one measures size and the other names a state."
        >
            <ServiceLatencySection :latency="DEMO_SERVICE_LATENCY" />
        </DemoBlock>

        <DemoBlock
            title="TracesTabs"
            description="the two questions traces answer, split. 'What happened to this request' is a list; 'which service is slow' is a chart, and they were competing for the same screen — a chart that grows a row per service pushed the traces themselves out of view. They are two pages now, wearing one toolbar and one query string: every tab link carries the current filters, so switching views never silently changes the window being read. Links, not local state, so a tab is a place with a URL."
        >
            <TracesTabs team-slug="acme" :query="{ from: '', to: '' }" />
        </DemoBlock>

        <DemoBlock
            title="TracesToolbar"
            description="the trace list's scope and window. It shares the range presets with the log viewer's toolbar, so a reader moving between the two surfaces keeps the same window vocabulary. The service filter matches a trace's root service — all the summary table knows — and 'errors only' reads the summed error count across the whole trace rather than the root span's own status. The Live control is optional and only rendered where there is something to poll: the list tab passes it, the latency tab does not, because a quantile that redrew every five seconds would be unreadable rather than current."
        >
            <TracesToolbar
                :projects="[
                    { name: 'Checkout', slug: 'checkout' },
                    { name: 'Ledger', slug: 'ledger' },
                ]"
                :project="demoProject"
                :service="demoService"
                :errors="demoErrors"
                :min-duration="demoMinDuration"
                range="1h"
                :can-reset="
                    demoProject !== null ||
                    demoService !== null ||
                    demoErrors ||
                    demoMinDuration !== null
                "
                @update:project="demoProject = $event"
                @update:service="demoService = $event"
                @update:errors="demoErrors = $event"
                :live="demoLive"
                @update:min-duration="demoMinDuration = $event"
                @update:live="demoLive = $event"
                @reset="
                    demoProject = null;
                    demoService = null;
                    demoErrors = false;
                    demoMinDuration = null;
                "
            />
        </DemoBlock>
    </div>
</template>
