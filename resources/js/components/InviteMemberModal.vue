<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { show as contactShow } from '@/routes/contact';
import { store as storeInvitation } from '@/routes/teams/invitations';
import type { PlanAllowance, RoleOption, Team } from '@/types';

type Props = {
    team: Team;
    availableRoles: RoleOption[];
    open: boolean;
    /**
     * The Free plan's seat allowance. Optional, and never a gate — the
     * caption appears once the seats are spent and the invitation still goes.
     */
    allowance?: PlanAllowance;
};

const props = defineProps<Props>();

const atAllowance = computed(
    () =>
        !!props.allowance &&
        props.allowance.limit > 0 &&
        props.allowance.used >= props.allowance.limit,
);
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const inviteRole = ref('member');
const formKey = ref(0);

function handleOpenChange(value: boolean) {
    emit('update:open', value);

    if (!value) {
        inviteRole.value = 'member';
        formKey.value++;
    }
}
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="storeInvitation.form(props.team.slug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="emit('update:open', false)"
            >
                <DialogHeader>
                    <DialogTitle>Invite a team member</DialogTitle>
                    <DialogDescription>
                        Send an invitation to join this team.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            name="email"
                            data-test="invite-email"
                            type="email"
                            placeholder="colleague@example.com"
                            required
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">Role</Label>
                        <Select
                            v-model="inviteRole"
                            name="role"
                            data-test="invite-role"
                        >
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Select a role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in props.availableRoles"
                                    :key="role.value"
                                    :value="role.value"
                                >
                                    {{ role.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.role" />
                    </div>

                    <p
                        v-if="atAllowance && props.allowance"
                        class="text-xs leading-relaxed text-muted-foreground"
                        data-test="invite-allowance"
                    >
                        This team already has
                        {{ props.allowance.used }} of the
                        {{ props.allowance.limit }} members the Free plan
                        publishes. You can still invite one — nothing is blocked
                        — but it is time to
                        <a
                            :href="
                                contactShow.url({ query: { topic: 'upgrade' } })
                            "
                            class="underline underline-offset-2 hover:text-foreground"
                            >talk about a Team plan</a
                        >.
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Cancel </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="invite-submit"
                        :disabled="processing"
                    >
                        Send invitation
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
