<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import ToolController from '@/actions/App/Http/Controllers/Assistant/ToolController';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { index as mcpServersIndex } from '@/routes/mcp-servers';
import { index } from '@/routes/tools';
import type { AssistantTool, McpServerTools } from '@/types';

defineProps<{
    builtin: AssistantTool[];
    servers: McpServerTools[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tools', href: index() }],
    },
});

const toggle = (tool: AssistantTool, enabled: boolean) => {
    router.put(
        ToolController.update.url(),
        { tool: tool.key, enabled },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Tools" />

    <div class="mx-auto flex max-w-3xl flex-col gap-8 p-4">
        <Heading
            title="Tools"
            description="Choose which tools your assistant is allowed to use"
        />

        <section class="space-y-3">
            <Heading variant="small" title="Built-in" />
            <label
                v-for="tool in builtin"
                :key="tool.key"
                class="flex items-start gap-3 rounded-lg border p-3"
            >
                <Checkbox
                    class="mt-0.5"
                    :model-value="tool.enabled"
                    @update:model-value="toggle(tool, $event === true)"
                />
                <span>
                    <span class="block font-mono text-sm">{{ tool.name }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        tool.description
                    }}</span>
                </span>
            </label>
        </section>

        <section v-for="server in servers" :key="server.id" class="space-y-3">
            <Heading
                variant="small"
                :title="server.name"
                description="MCP server"
            />

            <AlertError
                v-if="server.error"
                title="Could not connect to this server."
                :errors="[server.error]"
            />

            <p
                v-else-if="!server.tools.length"
                class="text-sm text-muted-foreground"
            >
                This server does not expose any tools.
            </p>

            <label
                v-for="tool in server.tools"
                :key="tool.key"
                class="flex items-start gap-3 rounded-lg border p-3"
            >
                <Checkbox
                    class="mt-0.5"
                    :model-value="tool.enabled"
                    @update:model-value="toggle(tool, $event === true)"
                />
                <span>
                    <span class="block font-mono text-sm">{{ tool.name }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        tool.description
                    }}</span>
                </span>
            </label>
        </section>

        <p v-if="!servers.length" class="text-sm text-muted-foreground">
            Connect an
            <Link :href="mcpServersIndex()" class="underline underline-offset-4"
                >MCP server</Link
            >
            to give your assistant more tools.
        </p>
    </div>
</template>
