<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, ShieldAlert, X } from '@lucide/vue';
import { reactive, ref } from 'vue';
import ConversationApprovalController from '@/actions/App/Http/Controllers/Assistant/ConversationApprovalController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { PendingApproval } from '@/types';

const props = defineProps<{
    conversationId: string;
    approvals: PendingApproval[];
}>();

/** The user's answer to each request, keyed by tool call ID. */
const decisions = reactive<Record<string, boolean>>({});
const submitting = ref(false);
const error = ref<string | undefined>();

// Every waiting request must be answered together, so submit once the last one is.
const decide = (id: string, approved: boolean) => {
    decisions[id] = approved;

    if (props.approvals.some((approval) => !(approval.id in decisions))) {
        return;
    }

    router.post(
        ConversationApprovalController.store.url(props.conversationId),
        { decisions },
        {
            preserveScroll: true,
            onStart: () => (submitting.value = true),
            onFinish: () => (submitting.value = false),
            onError: (errors) => {
                error.value = Object.values(errors)[0];
                Object.keys(decisions).forEach((key) => delete decisions[key]);
            },
        },
    );
};

const label = (name: string) =>
    name.charAt(0).toUpperCase() + name.slice(1).replace(/_/g, ' ');
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="approval in approvals"
            :key="approval.id"
            class="max-w-[80%] space-y-3 rounded-lg border border-amber-500/50 bg-amber-500/5 p-4 text-sm"
        >
            <p class="flex items-center gap-2 font-medium">
                <ShieldAlert class="h-4 w-4 text-amber-600" />
                {{ approval.reason ?? `Run ${approval.tool}` }}
            </p>

            <dl class="space-y-2">
                <div v-for="(value, name) in approval.arguments" :key="name">
                    <dt class="text-xs text-muted-foreground">
                        {{ label(String(name)) }}
                    </dt>
                    <dd
                        class="rounded bg-background px-2 py-1 whitespace-pre-wrap"
                    >
                        {{
                            typeof value === 'string'
                                ? value
                                : JSON.stringify(value, null, 2)
                        }}
                    </dd>
                </div>
            </dl>

            <div class="flex gap-2">
                <Button
                    size="sm"
                    :variant="
                        decisions[approval.id] === true ? 'default' : 'outline'
                    "
                    :disabled="submitting"
                    @click="decide(approval.id, true)"
                >
                    <Check class="h-4 w-4" /> Approve
                </Button>
                <Button
                    size="sm"
                    :variant="
                        decisions[approval.id] === false
                            ? 'destructive'
                            : 'outline'
                    "
                    :disabled="submitting"
                    @click="decide(approval.id, false)"
                >
                    <X class="h-4 w-4" /> Deny
                </Button>
            </div>
        </div>

        <InputError :message="error" />
    </div>
</template>
