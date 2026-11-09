<script setup lang="ts">
import AlertEventRow from '@/components/AlertEventRow.vue';
import AlertInstanceRow from '@/components/AlertInstanceRow.vue';
import AlertRuleRow from '@/components/AlertRuleRow.vue';
import AlertStateBadge from '@/components/AlertStateBadge.vue';
import AlertTemplateRow from '@/components/AlertTemplateRow.vue';
import NeedsAttentionCard from '@/components/NeedsAttentionCard.vue';
import NotificationChannelsCard from '@/components/NotificationChannelsCard.vue';
import {
    DEMO_ALERT_EVENTS,
    DEMO_ALERT_INSTANCES,
    DEMO_ALERT_NOW,
    DEMO_ALERT_RULES,
    DEMO_ALERT_TEMPLATES,
    DEMO_NEEDS_ATTENTION,
    DEMO_NOTIFICATION_CHANNELS,
} from '@/pages/styleguide/data';
import DemoBlock from './DemoBlock.vue';
</script>

<template>
    <div class="flex flex-col gap-6">
        <DemoBlock
            title="AlertStateBadge"
            description="where an alert stands. State colours are data: firing takes the severity-error token and pending — waiting out its `for` — severity-warn, both with a dot; OK is neutral chrome. A rule whose evaluation failed is a fact about the rule, not the telemetry, so it gets no hue: an ink fill with a warning glyph, louder than any neutral and impossible to mistake for firing. Disabled and muted are quiet outlines. Every variant carries its word."
        >
            <div class="flex flex-wrap gap-2">
                <AlertStateBadge state="firing" :count="3" />
                <AlertStateBadge state="pending" :count="1" />
                <AlertStateBadge state="inactive" />
                <AlertStateBadge state="error" />
                <AlertStateBadge state="disabled" />
                <AlertStateBadge state="muted" label="Muted until 18:00" />
            </div>
        </DemoBlock>

        <DemoBlock
            title="AlertInstanceRow"
            description="one series that is firing or pending — Prometheus' active alert — read as a sentence: this rule, for these labels, is at this value against that threshold, and has been for this long. Firing rows carry the severity-error edge a regressed issue does; a pending one has not happened yet and does not. A muted rule's series still list: muting silences notifications, not the page."
        >
            <div class="overflow-hidden rounded-md border bg-card">
                <AlertInstanceRow
                    v-for="instance in DEMO_ALERT_INSTANCES"
                    :key="instance.id"
                    :instance="instance"
                    team-slug="acme"
                    :now="DEMO_ALERT_NOW"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="AlertRuleRow"
            description="one rule in the Alerts list. The condition is said in the words every alerting surface uses (lib/alerts.ts formatCondition: 'p95 > 500 ms for 5m'), then what it measures and over which window, then where it sends. A rule whose query failed prints its error in ink under the channels. Mute, enable and delete sit behind the row's menu and emit — the page owns the requests. A rule Bilis set up from its catalogue wears a neutral 'Set up by Bilis' mark (no hue: it is a fact about the rule, not the telemetry) and the catalogue's one-line reason; '· edited' once a person changed it, after which Bilis stops updating it. Its destructive action reads 'Turn off' — the row is kept so provisioning never recreates it."
        >
            <div class="overflow-hidden rounded-md border bg-card">
                <AlertRuleRow
                    v-for="rule in DEMO_ALERT_RULES"
                    :key="rule.id"
                    :rule="rule"
                    team-slug="acme"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="AlertTemplateRow"
            description="one entry of Bilis' alert catalogue on the Alerts page's Templates tab, laid out like the rule it becomes — name, the catalogue's reason, the condition in lib/alerts.ts words. Status is neutral chrome (On, Turned off, Not set up): it is a fact about the rule, not the telemetry. One action: 'Turn on' installs a template now, without waiting for its data, or switches a turned-off one back on; 'Edit' once it is on. It emits; the page owns the request."
        >
            <div class="overflow-hidden rounded-md border bg-card">
                <AlertTemplateRow
                    v-for="template in DEMO_ALERT_TEMPLATES"
                    :key="template.key"
                    :template="template"
                    team-slug="acme"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="NeedsAttentionCard"
            description="the dashboard's first section: firing series, then pending ones, critical before warning before info, at most eight. Each says its value against the threshold in the words lib/alerts.ts formatReading uses everywhere ('4.2× normal' against '> 3× last week', '93%' against '> 90%', 'p95 812 ms'), and how long it has held; the rule name opens the rule, 'Explore' the window that tripped it. Deferred: a skeleton while it loads, and nothing at all when it loads empty. It ignores channels — a team with nowhere to send alerts still sees what is firing."
        >
            <div class="rounded-md border bg-card px-4 py-4">
                <NeedsAttentionCard
                    :attention="DEMO_NEEDS_ATTENTION"
                    team-slug="acme"
                    :now="DEMO_ALERT_NOW"
                />
            </div>
            <div class="rounded-md border bg-card px-4 py-4">
                <NeedsAttentionCard team-slug="acme" />
            </div>
        </DemoBlock>

        <DemoBlock
            title="AlertEventRow"
            description="one line of history. Only firing takes a hue — a severity-error dot — so the column reads as red marks where the trouble was; resolved is the same dot hollowed, a rule error is ink. A deleted rule's events stay, unlinked. 'Explore' opens the window the rule read, the same link its notification carried."
        >
            <div class="overflow-hidden rounded-md border bg-card">
                <AlertEventRow
                    v-for="event in DEMO_ALERT_EVENTS"
                    :key="event.id"
                    :event="event"
                    team-slug="acme"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="NotificationChannelsCard"
            description="where a team's alerts go, on the team settings page. A Slack or webhook URL is a credential, so a row shows only its host and the edit dialog leaves it blank to keep; a webhook's signing secret never reaches the browser after the one flash that follows its creation. The last failed delivery is printed in ink, and 'Send test' reports beside the channel in the words the server chose."
        >
            <NotificationChannelsCard
                team-slug="acme"
                :channels="DEMO_NOTIFICATION_CHANNELS"
                can-manage
            />
        </DemoBlock>
    </div>
</template>
