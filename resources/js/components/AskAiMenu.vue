<script setup lang="ts">
import { ClipboardCopy, Sparkles } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { toast } from 'vue-sonner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { AI_ASSISTANTS, askAiPrompt } from '@/lib/logRow';
import type { AiAssistant } from '@/lib/logRow';
import type { LogEntry } from '@/types';

/**
 * Hand one log line to a general-purpose assistant.
 *
 * The escape hatch for everybody autofix cannot help: no repository connected,
 * no model key, or simply a line nobody wants to spend an agent run on. The
 * prompt is built here rather than typed by the reader, so what reaches the
 * assistant carries the timestamp, the service and the attributes — the parts
 * people forget to paste and then get a worse answer for.
 *
 * Assistants without a documented prefill parameter (Gemini) get the prompt on
 * the clipboard and an empty window, said out loud in the toast rather than
 * silently losing the text.
 */
const props = defineProps<{
    entry: LogEntry;
    /** Rendered instead of the default trigger button. */
    align?: 'start' | 'end';
}>();

const { copy } = useClipboard({
    // navigator.clipboard needs a secure context; self-hosted installs often
    // run plain http, so fall back to the legacy execCommand path there.
    legacy: true,
});

function ask(assistant: AiAssistant) {
    const prompt = askAiPrompt(props.entry);
    const url = assistant.url(prompt);

    if (url === null) {
        copy(prompt);
        toast.info(
            `Prompt copied — paste it into ${assistant.label}, which cannot be opened with a question in it.`,
        );
        window.open(assistant.home, '_blank', 'noopener,noreferrer');

        return;
    }

    window.open(url, '_blank', 'noopener,noreferrer');
}

function copyPrompt() {
    copy(askAiPrompt(props.entry));
    toast.success('Prompt copied.');
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <slot>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 font-sans text-[11px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    data-test="log-ask-ai"
                >
                    <Sparkles class="size-3.5" /> Ask AI
                </button>
            </slot>
        </DropdownMenuTrigger>

        <DropdownMenuContent :align="align ?? 'end'" class="w-56">
            <DropdownMenuLabel
                class="text-xs font-normal text-muted-foreground"
            >
                Send this line, its attributes and its timestamp as a question.
            </DropdownMenuLabel>

            <DropdownMenuSeparator />

            <DropdownMenuItem
                v-for="assistant in AI_ASSISTANTS"
                :key="assistant.id"
                :data-test="`log-ask-${assistant.id}`"
                @select="ask(assistant)"
            >
                <!--
                  The brand's shape, the menu's ink: `currentColor` keeps the
                  chrome achromatic, so these sit with the rest of the
                  interface rather than shouting three different hues at it.
                -->
                <svg
                    viewBox="0 0 24 24"
                    fill="currentColor"
                    aria-hidden="true"
                    class="size-4 shrink-0"
                >
                    <path :d="assistant.icon" />
                </svg>
                {{ assistant.label }}
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuItem data-test="log-copy-prompt" @select="copyPrompt">
                <ClipboardCopy />
                Copy the prompt
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
