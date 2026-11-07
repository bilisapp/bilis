<script setup lang="ts">
import { Info } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { TeamInvitationContext } from '@/types';

type Props = {
    invitation: TeamInvitationContext;
    action: 'Log in' | 'Register';
};

defineProps<Props>();
</script>

<template>
    <div data-test="team-invitation-alert">
        <Alert class="bg-muted/40 [&>svg]:text-muted-foreground">
            <Info class="size-4" />
            <AlertDescription class="text-foreground">
                {{ action }} to join the "{{ invitation.teamName }}" team.
            </AlertDescription>
        </Alert>
    </div>
</template>
