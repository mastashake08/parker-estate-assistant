<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    FileText,
    Globe,
    Mail,
    MessageSquare,
    Plug,
    Plus,
    ShieldCheck,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConversationMessageController from '@/actions/App/Http/Controllers/Assistant/ConversationMessageController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index as chatIndex, show as chatShow } from '@/routes/chat';
import { index as mcpServers } from '@/routes/mcp-servers';
import { index as skills } from '@/routes/skills';
import { index as tools } from '@/routes/tools';
import type { ConversationSummary, DashboardStats, ModelStatus } from '@/types';

const props = defineProps<{
    stats: DashboardStats;
    recentConversations: ConversationSummary[];
    model: ModelStatus;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const page = usePage();

/** "Good morning, Jyrone", using the first word of the user's name. */
const greeting = computed(() => {
    const hour = new Date().getHours();
    const partOfDay =
        hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
    const firstName = page.props.auth.user.name.split(' ')[0];

    return `Good ${partOfDay}, ${firstName}`;
});

const today = new Date().toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
});

const message = ref('');

const suggestions = [
    'Give me a news briefing on today’s top stories',
    'What is the weather this weekend in Louisville?',
    'Research the best time of year to reseal a driveway',
    'Email me a reminder to renew the car registration',
];

const statCards = computed(() => [
    {
        title: 'Conversations',
        value: props.stats.conversations,
        detail: 'Remembered chats',
        href: chatIndex(),
        icon: MessageSquare,
    },
    {
        title: 'Documents',
        value: props.stats.documents,
        detail: 'Shared for analysis',
        href: chatIndex(),
        icon: FileText,
    },
    {
        title: 'Skills',
        value: props.stats.skills.enabled,
        detail: `${props.stats.skills.total} written`,
        href: skills(),
        icon: Sparkles,
    },
    {
        title: 'MCP servers',
        value: props.stats.mcpServers.enabled,
        detail: `${props.stats.mcpServers.total} connected`,
        href: mcpServers(),
        icon: Plug,
    },
]);

const capabilities = [
    {
        icon: Globe,
        title: 'Searches and reads the web',
        detail: 'Every answer links its sources.',
    },
    {
        icon: FileText,
        title: 'Analyzes your documents',
        detail: 'PDF, Word, text, Markdown and CSV.',
    },
    {
        icon: Mail,
        title: 'Sends email and texts',
        detail: 'Only after you approve each one.',
    },
];

const relativeTime = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
});

/** How long ago a conversation was active, such as "5 minutes ago". */
const timeAgo = (date: string) => {
    const seconds = (new Date(date).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return relativeTime.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
};
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4 md:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm text-muted-foreground">{{ today }}</p>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ greeting }}
                </h1>
            </div>
            <Button as-child>
                <Link :href="chatIndex()"
                    ><Plus class="h-4 w-4" /> New chat</Link
                >
            </Button>
        </div>

        <Card class="gap-4 bg-gradient-to-br from-primary/5 via-card to-card">
            <CardHeader>
                <CardTitle>What can I help with?</CardTitle>
                <CardDescription>
                    Ask anything. This starts a new conversation.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="ConversationMessageController.store.form()"
                    v-slot="{ errors, processing }"
                    class="space-y-3"
                >
                    <div class="flex gap-2">
                        <textarea
                            v-model="message"
                            name="message"
                            rows="2"
                            required
                            placeholder="Ask your assistant..."
                            class="flex-1 resize-none rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                            @keydown.enter.exact.prevent="
                                (
                                    $event.target as HTMLTextAreaElement
                                ).form?.requestSubmit()
                            "
                        />
                        <Button
                            type="submit"
                            class="self-end"
                            :disabled="processing"
                        >
                            <Spinner v-if="processing" />
                            <ArrowRight v-else class="h-4 w-4" />
                            Ask
                        </Button>
                    </div>
                    <InputError :message="errors.message" />
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="suggestion in suggestions"
                            :key="suggestion"
                            type="button"
                            class="rounded-full border bg-background px-3 py-1 text-xs text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground"
                            @click="message = suggestion"
                        >
                            {{ suggestion }}
                        </button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                v-for="card in statCards"
                :key="card.title"
                :href="card.href"
                class="group rounded-xl border bg-card p-5 shadow-sm transition-colors hover:border-primary/40"
            >
                <div
                    class="flex items-center justify-between text-sm text-muted-foreground"
                >
                    {{ card.title }}
                    <component
                        :is="card.icon"
                        class="h-4 w-4 transition-colors group-hover:text-foreground"
                    />
                </div>
                <p class="mt-2 text-3xl font-semibold tabular-nums">
                    {{ card.value }}
                </p>
                <p class="text-xs text-muted-foreground">{{ card.detail }}</p>
            </Link>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Recent conversations</CardTitle>
                    <CardDescription
                        >Pick up where you left off.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <div
                        v-if="!recentConversations.length"
                        class="flex flex-col items-center gap-3 rounded-lg border border-dashed py-10 text-center"
                    >
                        <MessageSquare
                            class="h-8 w-8 text-muted-foreground/60"
                        />
                        <p class="text-sm text-muted-foreground">
                            No conversations yet. Ask something above to start
                            one.
                        </p>
                    </div>
                    <ul v-else class="-mx-2 divide-y">
                        <li
                            v-for="conversation in recentConversations"
                            :key="conversation.id"
                        >
                            <Link
                                :href="chatShow(conversation.id)"
                                class="flex items-center gap-3 rounded-md px-2 py-3 transition-colors hover:bg-muted"
                            >
                                <MessageSquare
                                    class="h-4 w-4 shrink-0 text-muted-foreground"
                                />
                                <span class="flex-1 truncate text-sm">{{
                                    conversation.title
                                }}</span>
                                <span
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{
                                        timeAgo(conversation.updated_at)
                                    }}</span
                                >
                            </Link>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-4">
                <Card class="gap-4">
                    <CardHeader>
                        <CardTitle>Model</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="relative flex h-2.5 w-2.5">
                                <span
                                    v-if="model.installed"
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60"
                                />
                                <span
                                    class="relative inline-flex h-2.5 w-2.5 rounded-full"
                                    :class="
                                        model.installed
                                            ? 'bg-emerald-500'
                                            : 'bg-amber-500'
                                    "
                                />
                            </span>
                            <span class="font-mono">{{ model.name }}</span>
                        </div>
                        <p v-if="model.installed" class="text-muted-foreground">
                            Ready. {{ model.available.length }}
                            {{
                                model.available.length === 1
                                    ? 'model'
                                    : 'models'
                            }}
                            installed in Ollama.
                        </p>
                        <p
                            v-else-if="model.available.length"
                            class="text-muted-foreground"
                        >
                            Not installed. Run
                            <code class="rounded bg-muted px-1"
                                >ollama pull {{ model.name }}</code
                            >
                            or pick another model in the chat.
                        </p>
                        <p v-else class="text-muted-foreground">
                            Ollama can't be reached. Make sure it's running.
                        </p>
                    </CardContent>
                </Card>

                <Card class="gap-4">
                    <CardHeader>
                        <CardTitle>What your assistant can do</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div
                            v-for="capability in capabilities"
                            :key="capability.title"
                            class="flex gap-3"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted"
                            >
                                <component
                                    :is="capability.icon"
                                    class="h-4 w-4"
                                />
                            </div>
                            <div class="text-sm">
                                <p class="font-medium">
                                    {{ capability.title }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ capability.detail }}
                                </p>
                            </div>
                        </div>
                        <Link
                            :href="tools()"
                            class="flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            <ShieldCheck class="h-4 w-4" /> Choose which tools
                            it can use
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
