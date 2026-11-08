<script setup lang="ts">
import { router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { Hash, Mail, Pencil, Plus, Webhook, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import CopyableValue from '@/components/CopyableValue.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CHANNEL_TYPE_LABEL } from '@/lib/alerts';
import { formatRelativeTime } from '@/lib/logs';
import { cn } from '@/lib/utils';
import {
    destroy as destroyChannel,
    store as storeChannel,
    test as testChannel,
    toggle as toggleChannel,
    update as updateChannel,
} from '@/routes/teams/notification-channels';
import type {
    NotificationChannelSummary,
    NotificationChannelType,
} from '@/types';

/**
 * Where a team's alerts are delivered: email, a Slack incoming webhook, or a
 * signed generic webhook — listed with what each one delivers to, whether it
 * is on, and the last delivery that failed for good.
 *
 * The browser never holds a webhook URL or a signing secret: a row shows the
 * host, and the edit dialog leaves both blank, where blank means "keep what
 * is stored". A generated `whsec_` secret is flashed once, right after the
 * channel is created, and shown here to copy into the receiver.
 *
 * "Send test" delivers a clearly-labelled message now and prints how it went
 * beside the channel — the failure in the words the server chose, never a
 * URL. Only owners and admins (`canManage`) see the controls.
 */
const props = withDefaults(
    defineProps<{
        teamSlug: string;
        channels: NotificationChannelSummary[];
        canManage: boolean;
        /** Where the guide lives; the card links rather than restates. */
        docsHref?: string;
    }>(),
    { docsHref: undefined },
);

const page = usePage();

const secret = computed(() => page.flash.channelSecret ?? null);

const TYPE_ICON = { email: Mail, slack: Hash, webhook: Webhook } as const;

const TYPES: { value: NotificationChannelType; label: string; hint: string }[] =
    [
        { value: 'email', label: 'Email', hint: 'Team members or addresses' },
        { value: 'slack', label: 'Slack', hint: 'An incoming webhook URL' },
        {
            value: 'webhook',
            label: 'Webhook',
            hint: 'Signed with Standard Webhooks',
        },
    ];

/** The channel being edited; null while adding one. */
const editing = ref<NotificationChannelSummary | null>(null);
const dialogOpen = ref(false);

type ChannelForm = {
    type: NotificationChannelType;
    name: string;
    recipients: 'members' | 'addresses';
    addresses: string;
    url: string;
    secret: string;
    enabled: boolean;
    issue_events: boolean;
};

const form = useForm<ChannelForm>({
    type: 'email',
    name: '',
    recipients: 'members',
    addresses: '',
    url: '',
    secret: '',
    enabled: true,
    issue_events: false,
});

function openCreate() {
    editing.value = null;
    form.defaults({
        type: 'email',
        name: '',
        recipients: 'members',
        addresses: '',
        url: '',
        secret: '',
        enabled: true,
        issue_events: false,
    });
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(channel: NotificationChannelSummary) {
    editing.value = channel;
    form.defaults({
        type: channel.type,
        name: channel.name,
        recipients: channel.recipients === 'members' ? 'members' : 'addresses',
        addresses: channel.addresses.join('\n'),
        url: '',
        secret: '',
        enabled: channel.enabled,
        issue_events: channel.issueEvents,
    });
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

/** The posted body: the dialog's fields folded into the channel's `config`. */
function payload(data: ChannelForm) {
    const config: Record<string, unknown> =
        data.type === 'email'
            ? data.recipients === 'members'
                ? { recipients: 'members' }
                : { addresses: data.addresses }
            : data.type === 'slack'
              ? { url: data.url }
              : { url: data.url, secret: data.secret };

    return {
        type: data.type,
        name: data.name,
        enabled: data.enabled,
        issue_events: data.issue_events,
        config,
    };
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    form.transform(payload);

    if (editing.value) {
        form.patch(
            updateChannel([props.teamSlug, editing.value.id]).url,
            options,
        );
    } else {
        form.post(storeChannel(props.teamSlug).url, options);
    }
}

/** Errors arrive keyed `config.url`, `config.addresses.0`…; fold them per field. */
const configError = (key: string): string | undefined => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors[`config.${key}`] ??
        Object.entries(errors).find(([field]) =>
            field.startsWith(`config.${key}.`),
        )?.[1] ??
        (key === 'url' ? errors.config : undefined)
    );
};

function toggle(channel: NotificationChannelSummary) {
    router.post(
        toggleChannel([props.teamSlug, channel.id]).url,
        {},
        { preserveScroll: true },
    );
}

const deleting = ref<NotificationChannelSummary | null>(null);

function confirmDelete() {
    if (!deleting.value) {
        return;
    }

    router.delete(destroyChannel([props.teamSlug, deleting.value.id]).url, {
        preserveScroll: true,
        onSuccess: () => (deleting.value = null),
    });
}

/** The last test per channel: what it said, and whether it worked. */
const tests = ref<Record<number, { ok: boolean; message: string } | 'sending'>>(
    {},
);

const http = useHttp<Record<string, never>, { ok: boolean; message: string }>();

async function sendTest(channel: NotificationChannelSummary) {
    tests.value = { ...tests.value, [channel.id]: 'sending' };

    try {
        const result = await http.post(
            testChannel([props.teamSlug, channel.id]).url,
        );

        tests.value = { ...tests.value, [channel.id]: result };
    } catch {
        tests.value = {
            ...tests.value,
            [channel.id]: {
                ok: false,
                message: 'The test could not be sent. Try again in a moment.',
            },
        };
    }
}

/** The finished test for a channel, or null while none has run or one is sending. */
const testResult = (id: number): { ok: boolean; message: string } | null => {
    const result = tests.value[id];

    return result && result !== 'sending' ? result : null;
};

const lastFailure = (channel: NotificationChannelSummary) =>
    channel.lastFailedAt ? formatRelativeTime(channel.lastFailedAt) : null;
</script>

<template>
    <section class="flex flex-col gap-5" data-test="notification-channels">
        <Heading
            variant="small"
            title="Alert notifications"
            description="Where this team's alert rules send firing and resolved alerts. A rule picks its channels in the editor."
        >
            <template v-if="canManage" #actions>
                <Button
                    size="sm"
                    data-test="notification-channel-add"
                    @click="openCreate"
                >
                    <Plus /> Add channel
                </Button>
            </template>
        </Heading>

        <!-- A generated webhook secret, once: the receiver needs it to verify. -->
        <div
            v-if="secret"
            class="flex flex-col gap-2 rounded-lg border bg-card p-4"
            data-test="notification-channel-secret"
        >
            <p class="text-sm font-medium">
                Signing secret for {{ secret.name }}
            </p>
            <p class="text-xs text-muted-foreground">
                Configure your receiver with it now — it is stored encrypted and
                not shown again. Any Standard Webhooks library verifies the
                <code class="font-mono">webhook-signature</code> header with it.
            </p>
            <CopyableValue :value="secret.secret" label="Copy signing secret" />
        </div>

        <div v-if="channels.length">
            <div
                v-for="channel in channels"
                :key="channel.id"
                class="flex min-h-14 flex-wrap items-center justify-between gap-3 border-b border-border/60 py-2.5"
                :data-test="`notification-channel-${channel.id}`"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted"
                    >
                        <component
                            :is="TYPE_ICON[channel.type]"
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </span>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <div class="flex min-w-0 items-center gap-2">
                            <span
                                :class="
                                    cn(
                                        'truncate text-sm font-medium',
                                        !channel.enabled &&
                                            'text-muted-foreground',
                                    )
                                "
                                >{{ channel.name }}</span
                            >
                            <span
                                v-if="!channel.enabled"
                                class="inline-flex h-5 shrink-0 items-center rounded-full px-2 text-[11px] font-medium text-muted-foreground ring-1 ring-border ring-inset"
                                >Off</span
                            >
                            <span
                                v-if="channel.issueEvents"
                                class="inline-flex h-5 shrink-0 items-center rounded-full px-2 text-[11px] font-medium text-foreground/80 ring-1 ring-border ring-inset"
                                >Issues too</span
                            >
                        </div>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ CHANNEL_TYPE_LABEL[channel.type] }} ·
                            <span class="font-mono">{{ channel.target }}</span>
                        </p>
                        <p
                            v-if="channel.lastError"
                            class="text-xs text-foreground"
                            data-test="notification-channel-failure"
                        >
                            Last delivery failed
                            <template v-if="lastFailure(channel)">
                                {{ lastFailure(channel) }}</template
                            >: {{ channel.lastError }}
                        </p>
                        <p
                            v-if="testResult(channel.id)"
                            :class="
                                cn(
                                    'text-xs',
                                    testResult(channel.id)?.ok
                                        ? 'text-muted-foreground'
                                        : 'text-foreground',
                                )
                            "
                            role="status"
                            data-test="notification-channel-test-result"
                        >
                            {{ testResult(channel.id)?.message }}
                        </p>
                    </div>
                </div>

                <div v-if="canManage" class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="tests[channel.id] === 'sending'"
                        data-test="notification-channel-test"
                        @click="sendTest(channel)"
                    >
                        {{
                            tests[channel.id] === 'sending'
                                ? 'Sending…'
                                : 'Send test'
                        }}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        data-test="notification-channel-toggle"
                        @click="toggle(channel)"
                    >
                        {{ channel.enabled ? 'Turn off' : 'Turn on' }}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
                        data-test="notification-channel-edit"
                        @click="openEdit(channel)"
                    >
                        <Pencil class="size-4" />
                        <span class="sr-only">Edit {{ channel.name }}</span>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
                        data-test="notification-channel-delete"
                        @click="deleting = channel"
                    >
                        <X class="size-4" />
                        <span class="sr-only">Remove {{ channel.name }}</span>
                    </Button>
                </div>
            </div>
        </div>

        <p v-else class="max-w-prose text-sm text-muted-foreground">
            No channels yet, so alert rules record their history and send
            nothing.
            <template v-if="canManage"
                >Add an email address, a Slack incoming webhook or a webhook of
                your own.</template
            >
            <template v-else
                >An owner or admin of the team can add one.</template
            >
            <a
                v-if="docsHref"
                :href="docsHref"
                class="underline underline-offset-4 hover:text-foreground"
                >How alerting works</a
            >
        </p>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? `Edit ${editing.name}` : 'Add a channel'
                    }}</DialogTitle>
                    <DialogDescription>
                        Alerts are delivered when a series starts firing and
                        when it resolves. Muted rules send nothing.
                    </DialogDescription>
                </DialogHeader>

                <form
                    class="flex flex-col gap-5"
                    data-test="notification-channel-form"
                    @submit.prevent="submit"
                >
                    <div v-if="!editing" class="grid gap-2">
                        <Label>Type</Label>
                        <div class="grid grid-cols-3 gap-2" role="radiogroup">
                            <button
                                v-for="type in TYPES"
                                :key="type.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.type === type.value"
                                :class="
                                    cn(
                                        'flex flex-col items-start gap-0.5 rounded-md border px-3 py-2 text-left transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                        form.type === type.value
                                            ? 'border-ring bg-muted'
                                            : 'hover:bg-accent',
                                    )
                                "
                                :data-test="`notification-channel-type-${type.value}`"
                                @click="form.type = type.value"
                            >
                                <span class="text-sm font-medium">{{
                                    type.label
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    type.hint
                                }}</span>
                            </button>
                        </div>
                        <InputError :message="form.errors.type" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="channel-name">Name</Label>
                        <Input
                            id="channel-name"
                            v-model="form.name"
                            autocomplete="off"
                            :placeholder="
                                form.type === 'slack'
                                    ? '#alerts'
                                    : form.type === 'webhook'
                                      ? 'Incident bridge'
                                      : 'On-call inbox'
                            "
                            data-test="notification-channel-name"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <template v-if="form.type === 'email'">
                        <div class="grid gap-3">
                            <div class="flex items-center gap-3">
                                <Checkbox
                                    id="channel-members"
                                    :model-value="form.recipients === 'members'"
                                    data-test="notification-channel-members"
                                    @update:model-value="
                                        form.recipients =
                                            $event === true
                                                ? 'members'
                                                : 'addresses'
                                    "
                                />
                                <Label for="channel-members" class="font-normal"
                                    >Every member of this team</Label
                                >
                            </div>
                            <div
                                v-if="form.recipients === 'addresses'"
                                class="grid gap-2"
                            >
                                <Label for="channel-addresses"
                                    >Addresses, one per line</Label
                                >
                                <textarea
                                    id="channel-addresses"
                                    v-model="form.addresses"
                                    rows="3"
                                    spellcheck="false"
                                    class="min-h-20 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                    placeholder="oncall@acme.dev"
                                    data-test="notification-channel-addresses"
                                />
                                <InputError
                                    :message="
                                        configError('addresses') ??
                                        configError('recipients')
                                    "
                                />
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="grid gap-2">
                            <Label for="channel-url">{{
                                form.type === 'slack'
                                    ? 'Incoming webhook URL'
                                    : 'Endpoint URL'
                            }}</Label>
                            <Input
                                id="channel-url"
                                v-model="form.url"
                                type="url"
                                autocomplete="off"
                                spellcheck="false"
                                class="font-mono"
                                :placeholder="
                                    editing?.host
                                        ? `Stored for ${editing.host} — leave blank to keep`
                                        : form.type === 'slack'
                                          ? 'https://hooks.slack.com/services/…'
                                          : 'https://ops.acme.dev/hooks/bilis'
                                "
                                data-test="notification-channel-url"
                            />
                            <InputError :message="configError('url')" />
                        </div>

                        <div v-if="form.type === 'webhook'" class="grid gap-2">
                            <Label for="channel-secret"
                                >Signing secret
                                <span class="font-normal text-muted-foreground"
                                    >(optional)</span
                                ></Label
                            >
                            <Input
                                id="channel-secret"
                                v-model="form.secret"
                                type="password"
                                autocomplete="off"
                                class="font-mono"
                                :placeholder="
                                    editing?.hasSecret
                                        ? 'Stored — leave blank to keep'
                                        : 'whsec_… — leave blank to generate one'
                                "
                                data-test="notification-channel-secret-input"
                            />
                            <InputError :message="configError('secret')" />
                        </div>
                    </template>

                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="channel-issue-events"
                            :model-value="form.issue_events"
                            @update:model-value="
                                form.issue_events = $event === true
                            "
                        />
                        <div class="grid gap-1">
                            <Label
                                for="channel-issue-events"
                                class="font-normal"
                                >Also send new and regressed issues</Label
                            >
                        </div>
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancel</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="form.processing"
                            data-test="notification-channel-save"
                        >
                            {{ editing ? 'Save channel' : 'Add channel' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="deleting !== null"
            @update:open="(open) => !open && (deleting = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove {{ deleting?.name }}</DialogTitle>
                    <DialogDescription>
                        Rules that send here stop doing so; their history stays.
                        A Slack or webhook URL is not revoked at the other end —
                        do that there if it leaked.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        data-test="notification-channel-delete-confirm"
                        @click="confirmDelete"
                        >Remove channel</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
