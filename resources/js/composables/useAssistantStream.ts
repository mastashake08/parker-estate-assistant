import { usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { reactive } from 'vue';

type StreamPayload = {
    conversation_id: string;
    event: {
        type: string;
        delta?: string;
        message?: string;
        tool_name?: string;
    };
};

export type ReplyStream = {
    text: string;
    tools: string[];
    status: 'streaming' | 'complete' | 'error';
    error: string | null;
};

/**
 * Replies being streamed, keyed by conversation ID. Kept outside the component
 * so tokens that arrive before the chat page has the new conversation's ID
 * (or while it navigates there) are not lost.
 */
const streams = reactive<Record<string, ReplyStream>>({});

export function useAssistantStream() {
    const page = usePage();

    useEcho<StreamPayload>(
        `App.Models.User.${page.props.auth.user.id}`,
        '.assistant.stream',
        ({ conversation_id, event }) => {
            const stream = (streams[conversation_id] ??= {
                text: '',
                tools: [],
                status: 'streaming',
                error: null,
            });

            switch (event.type) {
                case 'text_delta':
                    stream.text += event.delta ?? '';
                    break;
                case 'tool_call':
                    stream.tools.push(event.tool_name ?? 'tool');
                    break;
                case 'error':
                    stream.status = 'error';
                    stream.error = event.message ?? 'Something went wrong.';
                    break;
                case 'reply_complete':
                    stream.status = 'complete';
                    break;
            }
        },
    );

    const clear = (conversationId: string) => {
        delete streams[conversationId];
    };

    return { streams, clear };
}
