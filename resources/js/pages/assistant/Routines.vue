<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Mail, MessageSquare, Play, Smartphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import RoutineController from '@/actions/App/Http/Controllers/Assistant/RoutineController';
import RoutineRunController from '@/actions/App/Http/Controllers/Assistant/RoutineRunController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as chatShow } from '@/routes/chat';
import { index } from '@/routes/routines';
import type {
    Routine,
    RoutineFrequency,
    RoutineRunStatus,
    ToolCatalog,
} from '@/types';

const props = defineProps<{
    routines: Routine[];
    tools: ToolCatalog;
    models: string[];
    defaultModel: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Routines', href: index() }],
    },
});

const page = usePage();

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-input/30';

const days = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const timezones = Intl.supportedValuesOf('timeZone');

/** Every tool a routine can use, with the server it comes from. */
const toolGroups = computed(() => [
    { name: 'Built-in', tools: props.tools.builtin, error: null },
    ...props.tools.servers.map((server) => ({
        name: server.name,
        tools: server.tools.map((tool) => ({ ...tool, needsApproval: false })),
        error: server.error,
    })),
]);

const approvalTools = computed(
    () =>
        new Set(
            props.tools.builtin
                .filter((tool) => tool.needsApproval)
                .map((tool) => tool.key),
        ),
);

const blank = () => ({
    name: '',
    instructions: '',
    frequency: 'daily' as RoutineFrequency,
    time: '08:00',
    day_of_week: 1 as number | null,
    every_hours: 1 as number | null,
    cron: '',
    timezone: browserTimezone,
    model: '',
    tools: props.tools.builtin
        .filter((tool) => !tool.needsApproval)
        .map((tool) => tool.key),
    auto_approve: false,
    deliver_email: false,
    deliver_sms: false,
    sms_to: '',
    enabled: true,
});

const editing = ref<Routine | null>(null);
const form = useForm(blank());

const usesApprovalTools = computed(() =>
    form.tools.some((key) => approvalTools.value.has(key)),
);

const edit = (routine: Routine) => {
    editing.value = routine;
    form.clearErrors();
    form.defaults({
        name: routine.name,
        instructions: routine.instructions,
        frequency: routine.frequency,
        time: routine.time ?? '08:00',
        day_of_week: routine.day_of_week ?? 1,
        every_hours: routine.every_hours ?? 1,
        cron: routine.frequency === 'custom' ? routine.cron : '',
        timezone: routine.timezone,
        model: routine.model ?? '',
        tools: [...routine.tools],
        auto_approve: routine.auto_approve,
        deliver_email: routine.deliver_email,
        deliver_sms: routine.deliver_sms,
        sms_to: routine.sms_to ?? '',
        enabled: routine.enabled,
    });
    form.reset();
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
};

const cancel = () => {
    editing.value = null;
    form.defaults(blank());
    form.reset();
};

const toggleTool = (key: string, selected: boolean) => {
    form.tools = selected
        ? [...form.tools, key]
        : form.tools.filter((tool) => tool !== key);
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: cancel };

    // Only send the schedule fields the chosen frequency uses.
    form.transform((data) => ({
        ...data,
        time: ['daily', 'weekdays', 'weekly'].includes(data.frequency)
            ? data.time
            : null,
        day_of_week: data.frequency === 'weekly' ? data.day_of_week : null,
        every_hours: data.frequency === 'hourly' ? data.every_hours : null,
        cron: data.frequency === 'custom' ? data.cron : null,
        model: data.model || null,
        auto_approve: usesApprovalTools.value && data.auto_approve,
        sms_to: data.deliver_sms ? data.sms_to : null,
    }));

    if (editing.value) {
        form.put(RoutineController.update.url(editing.value.id), options);
    } else {
        form.post(RoutineController.store.url(), options);
    }
};

const runNow = (routine: Routine) =>
    router.post(
        RoutineRunController.store.url(routine.id),
        {},
        { preserveScroll: true },
    );

const remove = (routine: Routine) => {
    if (
        confirm(
            `Delete ${routine.name}? The chats from its earlier runs are kept.`,
        )
    ) {
        router.delete(RoutineController.destroy.url(routine.id), {
            preserveScroll: true,
        });
    }
};

/** A date and time such as "Thu, Nov 5, 8:00 AM" in the routine's timezone. */
const formatTime = (value: string, timezone: string) =>
    new Date(value).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: timezone,
    });

const statusLabels: Record<RoutineRunStatus, string> = {
    running: 'Running',
    completed: 'Done',
    waiting_for_approval: 'Waiting for approval',
    failed: 'Failed',
};

const statusVariant = (status: RoutineRunStatus) =>
    status === 'failed'
        ? 'destructive'
        : status === 'completed'
          ? 'secondary'
          : 'outline';
</script>

<template>
    <Head title="Routines" />

    <div class="mx-auto flex max-w-3xl flex-col gap-8 p-4">
        <Heading
            title="Routines"
            description="Have your assistant do something on a schedule, such as a morning brief at 8 AM"
        />

        <div class="space-y-3">
            <p v-if="!routines.length" class="text-sm text-muted-foreground">
                No routines yet. Add one below.
            </p>

            <div
                v-for="routine in routines"
                :key="routine.id"
                class="space-y-3 rounded-lg border p-4"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 space-y-1">
                        <div
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            {{ routine.name }}
                            <Badge variant="secondary">{{
                                routine.schedule
                            }}</Badge>
                            <Badge v-if="!routine.enabled" variant="outline"
                                >paused</Badge
                            >
                            <Badge v-if="routine.auto_approve" variant="outline"
                                >sends without asking</Badge
                            >
                        </div>
                        <p class="line-clamp-2 text-sm text-muted-foreground">
                            {{ routine.instructions }}
                        </p>
                        <p
                            class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
                        >
                            <span v-if="routine.enabled && routine.next_run_at"
                                >Next:
                                {{
                                    formatTime(
                                        routine.next_run_at,
                                        routine.timezone,
                                    )
                                }}</span
                            >
                            <span class="flex items-center gap-1"
                                ><MessageSquare class="h-3 w-3" /> Chat</span
                            >
                            <span
                                v-if="routine.deliver_email"
                                class="flex items-center gap-1"
                                ><Mail class="h-3 w-3" />
                                {{ page.props.auth.user.email }}</span
                            >
                            <span
                                v-if="routine.deliver_sms"
                                class="flex items-center gap-1"
                                ><Smartphone class="h-3 w-3" />
                                {{ routine.sms_to }}</span
                            >
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="runNow(routine)"
                            ><Play class="h-4 w-4" /> Run now</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            @click="edit(routine)"
                            >Edit</Button
                        >
                        <Button
                            variant="destructive"
                            size="sm"
                            @click="remove(routine)"
                            >Delete</Button
                        >
                    </div>
                </div>

                <ul v-if="routine.runs.length" class="space-y-1 border-t pt-3">
                    <li
                        v-for="run in routine.runs"
                        :key="run.id"
                        class="flex flex-wrap items-center gap-2 text-xs"
                    >
                        <Badge :variant="statusVariant(run.status)">{{
                            statusLabels[run.status]
                        }}</Badge>
                        <span class="text-muted-foreground">{{
                            formatTime(run.created_at, routine.timezone)
                        }}</span>
                        <Link
                            v-if="run.conversation_id"
                            :href="chatShow(run.conversation_id)"
                            class="underline underline-offset-4 hover:text-primary"
                            >Open chat</Link
                        >
                        <span v-if="run.error" class="text-destructive">{{
                            run.error
                        }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <form class="space-y-5 rounded-lg border p-4" @submit.prevent="submit">
            <Heading
                variant="small"
                :title="editing ? `Edit ${editing.name}` : 'Add a routine'"
            />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    placeholder="Morning brief"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="instructions">What should it do?</Label>
                <textarea
                    id="instructions"
                    v-model="form.instructions"
                    rows="5"
                    required
                    :class="textareaClass"
                    placeholder="Give me a morning brief: today's weather in Louisville, the top 5 news headlines with links, and anything on my calendar."
                />
                <InputError :message="form.errors.instructions" />
            </div>

            <fieldset class="grid gap-3">
                <legend class="mb-2 text-sm font-medium">When</legend>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="grid gap-2">
                        <Label for="frequency">Repeat</Label>
                        <select
                            id="frequency"
                            v-model="form.frequency"
                            :class="selectClass"
                        >
                            <option value="daily">Every day</option>
                            <option value="weekdays">Weekdays</option>
                            <option value="weekly">Every week</option>
                            <option value="hourly">Every few hours</option>
                            <option value="custom">Custom (cron)</option>
                        </select>
                    </div>
                    <div v-if="form.frequency === 'weekly'" class="grid gap-2">
                        <Label for="day_of_week">On</Label>
                        <select
                            id="day_of_week"
                            v-model.number="form.day_of_week"
                            :class="selectClass"
                        >
                            <option
                                v-for="(day, number) in days"
                                :key="day"
                                :value="number"
                            >
                                {{ day }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="
                            ['daily', 'weekdays', 'weekly'].includes(
                                form.frequency,
                            )
                        "
                        class="grid gap-2"
                    >
                        <Label for="time">At</Label>
                        <Input
                            id="time"
                            v-model="form.time"
                            type="time"
                            class="w-32"
                        />
                    </div>
                    <div v-if="form.frequency === 'hourly'" class="grid gap-2">
                        <Label for="every_hours">Every</Label>
                        <select
                            id="every_hours"
                            v-model.number="form.every_hours"
                            :class="selectClass"
                        >
                            <option
                                v-for="hours in [1, 2, 3, 4, 6, 8, 12]"
                                :key="hours"
                                :value="hours"
                            >
                                {{ hours === 1 ? 'hour' : `${hours} hours` }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="form.frequency === 'custom'"
                        class="grid flex-1 gap-2"
                    >
                        <Label for="cron">Cron expression</Label>
                        <Input
                            id="cron"
                            v-model="form.cron"
                            class="font-mono"
                            placeholder="0 8 * * 1-5"
                        />
                    </div>
                </div>
                <p
                    v-if="form.frequency === 'custom'"
                    class="text-xs text-muted-foreground"
                >
                    Minute, hour, day of month, month, day of week. For example,
                    <code>0 8 * * 1-5</code> is 8:00 AM on weekdays.
                </p>
                <InputError
                    :message="
                        form.errors.time ??
                        form.errors.day_of_week ??
                        form.errors.every_hours ??
                        form.errors.cron
                    "
                />
                <div class="grid gap-2">
                    <Label for="timezone">Timezone</Label>
                    <select
                        id="timezone"
                        v-model="form.timezone"
                        :class="selectClass"
                    >
                        <option v-for="zone in timezones" :key="zone">
                            {{ zone }}
                        </option>
                    </select>
                    <InputError :message="form.errors.timezone" />
                </div>
            </fieldset>

            <div class="grid gap-2">
                <Label for="model">Model</Label>
                <select id="model" v-model="form.model" :class="selectClass">
                    <option value="">Default ({{ defaultModel }})</option>
                    <option v-for="name in models" :key="name" :value="name">
                        {{ name }}
                    </option>
                </select>
            </div>

            <fieldset class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Tools it may use
                </legend>
                <div
                    v-for="group in toolGroups"
                    :key="group.name"
                    class="space-y-2"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        {{ group.name }}
                    </p>
                    <p v-if="group.error" class="text-xs text-destructive">
                        {{ group.error }}
                    </p>
                    <Label
                        v-for="tool in group.tools"
                        :key="tool.key"
                        class="flex items-start gap-2 font-normal"
                    >
                        <Checkbox
                            class="mt-0.5"
                            :model-value="form.tools.includes(tool.key)"
                            @update:model-value="
                                toggleTool(tool.key, $event === true)
                            "
                        />
                        <span>
                            <span class="font-mono text-sm">{{
                                tool.name
                            }}</span>
                            <Badge
                                v-if="tool.needsApproval"
                                variant="outline"
                                class="ml-2"
                                >needs approval</Badge
                            >
                        </span>
                    </Label>
                </div>
                <InputError
                    :message="
                        form.errors.tools ??
                        Object.entries(form.errors).find(([field]) =>
                            field.startsWith('tools.'),
                        )?.[1]
                    "
                />
            </fieldset>

            <div
                v-if="usesApprovalTools"
                class="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
            >
                <Label class="flex items-center gap-2">
                    <Checkbox
                        :model-value="form.auto_approve"
                        @update:model-value="
                            form.auto_approve = $event === true
                        "
                    />
                    Send emails and texts without asking me
                </Label>
                <p class="text-xs text-muted-foreground">
                    Otherwise the run pauses until you approve in its chat. If
                    you turn this on, the assistant can message anyone,
                    including someone it gets wrong.
                </p>
            </div>

            <fieldset class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Send me the result
                </legend>
                <p class="text-xs text-muted-foreground">
                    Every run is saved as a chat. It can also be sent to you.
                </p>
                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox
                        :model-value="form.deliver_email"
                        @update:model-value="
                            form.deliver_email = $event === true
                        "
                    />
                    Email it to {{ page.props.auth.user.email }}
                </Label>
                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox
                        :model-value="form.deliver_sms"
                        @update:model-value="form.deliver_sms = $event === true"
                    />
                    Text it to me
                </Label>
                <div v-if="form.deliver_sms" class="grid gap-2 pl-6">
                    <Input
                        v-model="form.sms_to"
                        type="tel"
                        placeholder="+15025550123"
                        class="max-w-56"
                    />
                    <InputError :message="form.errors.sms_to" />
                </div>
            </fieldset>

            <Label class="flex items-center gap-2">
                <Checkbox
                    :model-value="form.enabled"
                    @update:model-value="form.enabled = $event === true"
                />
                Enabled
            </Label>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">
                    {{ editing ? 'Save' : 'Add routine' }}
                </Button>
                <Button
                    v-if="editing"
                    type="button"
                    variant="ghost"
                    @click="cancel"
                    >Cancel</Button
                >
            </div>
        </form>
    </div>
</template>
