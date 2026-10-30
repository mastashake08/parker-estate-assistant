<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { FileText, Paperclip, Plus, Trash2, Wrench, X } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import ConversationController from '@/actions/App/Http/Controllers/Assistant/ConversationController';
import ConversationMessageController from '@/actions/App/Http/Controllers/Assistant/ConversationMessageController';
import ApprovalRequests from '@/components/ApprovalRequests.vue';
import InputError from '@/components/InputError.vue';
import ToolUsage from '@/components/ToolUsage.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAssistantStream } from '@/composables/useAssistantStream';
import { index as chatIndex, show as chatShow } from '@/routes/chat';
import type { Conversation, ConversationSummary } from '@/types';

const props = defineProps<{
    conversations: ConversationSummary[];
    conversation: Conversation | null;
    models: string[];
    defaultModel: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Chat', href: chatIndex() }],
    },
});

const page = usePage();
const { streams, clear } = useAssistantStream();

/** The message just sent, shown until the finished exchange has been stored. */
const pendingMessage = computed(
    () => page.flash.pendingMessage as string | undefined,
);

/** The names of the documents sent with the pending message. */
const pendingDocuments = computed(
    () => (page.flash.pendingDocuments as string[] | undefined) ?? [],
);

/** The documents chosen to send with the next message. */
const documentInput = ref<HTMLInputElement | null>(null);
const chosenDocuments = ref<string[]>([]);

const chooseDocuments = (event: Event) => {
    chosenDocuments.value = Array.from(
        (event.target as HTMLInputElement).files ?? [],
    ).map((file) => file.name);
};

const clearDocuments = () => {
    if (documentInput.value) {
        documentInput.value.value = '';
    }

    chosenDocuments.value = [];
};

/** A file size such as "12 KB". */
const fileSize = (bytes: number) =>
    bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

/** Set after approving or denying tool calls, while the rest of the reply streams. */
const resumingReply = computed(() => page.flash.resumingReply === true);

const stream = computed(() =>
    props.conversation ? streams[props.conversation.id] : undefined,
);

/** The conversation's model, else the default if it is installed, else the first installed model. */
const initialModel = () =>
    props.conversation?.model ??
    (props.models.includes(props.defaultModel)
        ? props.defaultModel
        : (props.models[0] ?? ''));

const model = ref(initialModel());

watch(
    () => props.conversation?.id,
    () => (model.value = initialModel()),
);

const awaitingReply = computed(
    () =>
        (!!pendingMessage.value || resumingReply.value) &&
        stream.value?.status !== 'error',
);

// Once the reply has been stored, load it from the server and drop the live copy.
watch(
    () => stream.value?.status,
    (status) => {
        const id = props.conversation?.id;

        if (status === 'complete' && id) {
            router.reload({
                only: ['conversation', 'conversations'],
                onSuccess: () => clear(id),
            });
        }
    },
    { immediate: true },
);

/** The site a source is on, such as "bbc.com". */
const hostname = (url: string) => new URL(url).hostname.replace(/^www\./, '');

const thread = ref<HTMLElement | null>(null);

const scrollToLatest = () =>
    nextTick(() => thread.value?.scrollTo({ top: thread.value.scrollHeight }));

onMounted(scrollToLatest);
watch(
    () => [
        props.conversation?.messages.length,
        pendingMessage.value,
        stream.value?.text,
    ],
    scrollToLatest,
);

const deleteConversation = (id: string) => {
    if (confirm('Delete this conversation? The assistant will forget it.')) {
        router.delete(ConversationController.destroy.url(id));
    }
};
</script>

<template>
    <Head :title="conversation?.title ?? 'Chat'" />

    <div class="flex h-[calc(100vh-8rem)] gap-4 p-4">
        <aside
            class="hidden w-64 shrink-0 flex-col gap-2 overflow-y-auto md:flex"
        >
            <Button variant="outline" as-child>
                <Link :href="chatIndex()"
                    ><Plus class="h-4 w-4" /> New chat</Link
                >
            </Button>

            <div
                v-for="item in conversations"
                :key="item.id"
                class="group flex items-center gap-1 rounded-md"
                :class="{ 'bg-muted': item.id === conversation?.id }"
            >
                <Link
                    :href="chatShow(item.id)"
                    class="flex-1 truncate px-3 py-2 text-sm"
                >
                    {{ item.title }}
                </Link>
                <Button
                    variant="ghost"
                    size="icon"
                    class="opacity-0 group-hover:opacity-100"
                    :aria-label="`Delete ${item.title}`"
                    @click="deleteConversation(item.id)"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>
        </aside>

        <section
            class="flex flex-1 flex-col rounded-xl border border-sidebar-border/70"
        >
            <div
                v-if="conversation?.documents.length"
                class="flex flex-wrap items-center gap-2 border-b px-4 py-2 text-xs"
            >
                <span class="text-muted-foreground">Shared documents:</span>
                <span
                    v-for="document in conversation.documents"
                    :key="document.id"
                    class="flex items-center gap-1 rounded-md bg-muted px-2 py-1"
                >
                    <FileText class="h-3 w-3" />
                    {{ document.name }}
                    <span class="text-muted-foreground">{{
                        fileSize(document.size)
                    }}</span>
                </span>
            </div>

            <div ref="thread" class="flex-1 space-y-4 overflow-y-auto p-4">
                <p
                    v-if="!conversation"
                    class="mt-12 text-center text-sm text-muted-foreground"
                >
                    Start a conversation. The assistant remembers everything
                    said in a chat.
                </p>

                <div
                    v-for="message in conversation?.messages ?? []"
                    :key="message.id"
                    class="flex"
                    :class="
                        message.role === 'user'
                            ? 'justify-end'
                            : 'justify-start'
                    "
                >
                    <div
                        class="max-w-[80%] space-y-2 rounded-lg px-4 py-2 text-sm"
                        :class="
                            message.role === 'user'
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted'
                        "
                    >
                        <ToolUsage
                            v-for="(tool, index) in message.tools"
                            :key="index"
                            :tool="tool"
                        />
                        <!-- Rendered on the server from Markdown, with any HTML the model wrote escaped. -->
                        <div
                            v-if="message.html"
                            class="markdown"
                            v-html="message.html"
                        />
                        <p
                            v-else-if="message.content"
                            class="whitespace-pre-wrap"
                        >
                            {{ message.content }}
                        </p>
                        <div
                            v-if="message.sources.length"
                            class="border-t border-border/60 pt-2 text-xs"
                        >
                            <p class="mb-1 font-medium text-muted-foreground">
                                Sources
                            </p>
                            <ol class="list-decimal space-y-0.5 pl-5">
                                <li
                                    v-for="source in message.sources"
                                    :key="source.url"
                                >
                                    <a
                                        :href="source.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="underline underline-offset-4 hover:text-primary"
                                        >{{ source.title }}</a
                                    >
                                    <span class="text-muted-foreground">
                                        · {{ hostname(source.url) }}</span
                                    >
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>

                <ApprovalRequests
                    v-if="conversation?.approvals.length && !awaitingReply"
                    :conversation-id="conversation.id"
                    :approvals="conversation.approvals"
                />

                <template v-if="pendingMessage || resumingReply">
                    <div v-if="pendingMessage" class="flex justify-end">
                        <div
                            class="max-w-[80%] rounded-lg bg-primary px-4 py-2 text-sm whitespace-pre-wrap text-primary-foreground"
                        >
                            {{ pendingMessage }}
                            <p
                                v-for="name in pendingDocuments"
                                :key="name"
                                class="mt-1 flex items-center gap-1 text-xs opacity-80"
                            >
                                <Paperclip class="h-3 w-3" /> {{ name }}
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-start">
                        <div
                            class="max-w-[80%] space-y-2 rounded-lg bg-muted px-4 py-2 text-sm whitespace-pre-wrap"
                        >
                            <p
                                v-for="(tool, index) in stream?.tools ?? []"
                                :key="index"
                                class="flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <Wrench class="h-3 w-3" /> Used {{ tool }}
                            </p>
                            <p
                                v-if="stream?.status === 'error'"
                                class="text-destructive"
                            >
                                {{ stream.error }}
                            </p>
                            <template v-else-if="stream?.text">{{
                                stream.text
                            }}</template>
                            <Spinner v-else />
                        </div>
                    </div>
                </template>
            </div>

            <Form
                v-bind="ConversationMessageController.store.form()"
                :reset-on-success="['message']"
                class="border-t p-4"
                v-slot="{ errors, processing }"
                @success="clearDocuments"
            >
                <div
                    v-if="chosenDocuments.length"
                    class="mb-2 flex flex-wrap items-center gap-2 text-xs"
                >
                    <span
                        v-for="name in chosenDocuments"
                        :key="name"
                        class="flex items-center gap-1 rounded-md bg-muted px-2 py-1"
                    >
                        <Paperclip class="h-3 w-3" /> {{ name }}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-6 px-2"
                        @click="clearDocuments"
                    >
                        <X class="h-3 w-3" /> Remove
                    </Button>
                </div>
                <input
                    v-if="conversation"
                    type="hidden"
                    name="conversation_id"
                    :value="conversation.id"
                />
                <div class="flex gap-2">
                    <input
                        ref="documentInput"
                        type="file"
                        name="documents[]"
                        multiple
                        accept=".pdf,.docx,.txt,.md,.csv"
                        class="hidden"
                        @change="chooseDocuments"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        class="self-end"
                        aria-label="Attach documents"
                        title="Attach PDF, Word, text, Markdown or CSV files"
                        @click="documentInput?.click()"
                    >
                        <Paperclip class="h-4 w-4" />
                    </Button>
                    <textarea
                        name="message"
                        rows="2"
                        required
                        placeholder="Ask your assistant..."
                        class="flex-1 resize-none rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        @keydown.enter.exact.prevent="
                            (
                                $event.target as HTMLTextAreaElement
                            ).form?.requestSubmit()
                        "
                    />
                    <select
                        v-if="models.length"
                        v-model="model"
                        name="model"
                        aria-label="Model"
                        class="h-9 max-w-40 self-end rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                    >
                        <option
                            v-for="name in models"
                            :key="name"
                            :value="name"
                        >
                            {{ name }}
                        </option>
                    </select>
                    <Button
                        type="submit"
                        class="self-end"
                        :disabled="processing || awaitingReply"
                    >
                        <Spinner v-if="processing || awaitingReply" />
                        Send
                    </Button>
                </div>
                <InputError class="mt-2" :message="errors.message" />
                <InputError class="mt-2" :message="errors.model" />
                <InputError
                    class="mt-2"
                    :message="
                        errors.documents ??
                        Object.entries(errors).find(([field]) =>
                            field.startsWith('documents.'),
                        )?.[1]
                    "
                />
            </Form>
        </section>
    </div>
</template>
