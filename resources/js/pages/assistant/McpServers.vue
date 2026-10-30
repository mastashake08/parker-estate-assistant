<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { LogIn } from '@lucide/vue';
import { ref } from 'vue';
import McpServerController from '@/actions/App/Http/Controllers/Assistant/McpServerController';
import McpServerOAuthController from '@/actions/App/Http/Controllers/Assistant/McpServerOAuthController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/mcp-servers';
import type { McpServer, McpTransport } from '@/types';

defineProps<{
    servers: McpServer[];
    stdioEnabled: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'MCP servers', href: index() }],
    },
});

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const editing = ref<McpServer | null>(null);

const form = useForm({
    name: '',
    transport: 'stdio' as McpTransport,
    command: '',
    args: '',
    env: '',
    url: '',
    headers: '',
    oauth_client_id: '',
    oauth_client_secret: '',
    oauth_scope: '',
    enabled: true,
});

/** Parse "KEY=value" (or "Name: value") lines into an object, skipping blanks. */
const parsePairs = (text: string, separator: string): Record<string, string> =>
    Object.fromEntries(
        text
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line.includes(separator))
            .map((line) => {
                const at = line.indexOf(separator);

                return [
                    line.slice(0, at).trim(),
                    line.slice(at + separator.length).trim(),
                ];
            }),
    );

const payload = (data: typeof form.data extends () => infer T ? T : never) => {
    const env = parsePairs(data.env, '=');
    const headers = parsePairs(data.headers, ':');

    return {
        name: data.name,
        transport: data.transport,
        command: data.command || null,
        args: data.args
            .split('\n')
            .map((arg) => arg.trim())
            .filter(Boolean),
        url: data.url || null,
        oauth_client_id: data.oauth_client_id || null,
        oauth_scope: data.oauth_scope || null,
        enabled: data.enabled,
        // Secrets are never sent back to the browser, so only overwrite them when new values are entered.
        ...(Object.keys(env).length ? { env } : {}),
        ...(Object.keys(headers).length ? { headers } : {}),
        ...(data.oauth_client_secret
            ? { oauth_client_secret: data.oauth_client_secret }
            : {}),
    };
};

const edit = (server: McpServer) => {
    editing.value = server;
    form.clearErrors();
    form.defaults({
        name: server.name,
        transport: server.transport,
        command: server.command ?? '',
        args: (server.args ?? []).join('\n'),
        env: '',
        url: server.url ?? '',
        headers: '',
        oauth_client_id: server.oauth_client_id ?? '',
        oauth_client_secret: '',
        oauth_scope: server.oauth_scope ?? '',
        enabled: server.enabled,
    });
    form.reset();
};

const cancel = () => {
    editing.value = null;
    form.defaults({
        name: '',
        transport: 'stdio',
        command: '',
        args: '',
        env: '',
        url: '',
        headers: '',
        oauth_client_id: '',
        oauth_client_secret: '',
        oauth_scope: '',
        enabled: true,
    });
    form.reset();
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: cancel };

    form.transform(payload);

    if (editing.value) {
        form.put(McpServerController.update.url(editing.value.id), options);
    } else {
        form.post(McpServerController.store.url(), options);
    }
};

/** Leave for the server's sign-in page, which sends the user back here. */
const signIn = (server: McpServer) =>
    router.post(McpServerOAuthController.store.url(server.id));

const signOut = (server: McpServer) => {
    if (confirm(`Sign out of ${server.name}?`)) {
        router.delete(McpServerOAuthController.destroy.url(server.id), {
            preserveScroll: true,
        });
    }
};

const remove = (server: McpServer) => {
    if (confirm(`Remove ${server.name}?`)) {
        router.delete(McpServerController.destroy.url(server.id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="MCP servers" />

    <div class="mx-auto flex max-w-3xl flex-col gap-8 p-4">
        <Heading
            title="MCP servers"
            description="Connect Model Context Protocol servers to give your assistant more tools"
        />

        <div class="space-y-3">
            <p v-if="!servers.length" class="text-sm text-muted-foreground">
                No MCP servers connected yet.
            </p>

            <div
                v-for="server in servers"
                :key="server.id"
                class="flex items-center justify-between gap-4 rounded-lg border p-4"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-medium">
                        {{ server.name }}
                        <Badge variant="secondary">{{
                            server.transport
                        }}</Badge>
                        <Badge v-if="server.signed_in">signed in</Badge>
                        <Badge v-if="!server.enabled" variant="outline"
                            >disabled</Badge
                        >
                    </div>
                    <p class="truncate font-mono text-xs text-muted-foreground">
                        {{
                            server.transport === 'http'
                                ? server.url
                                : [server.command, ...(server.args ?? [])].join(
                                      ' ',
                                  )
                        }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <template v-if="server.transport === 'http'">
                        <Button
                            v-if="server.signed_in"
                            variant="ghost"
                            size="sm"
                            @click="signOut(server)"
                            >Sign out</Button
                        >
                        <Button
                            v-else
                            variant="outline"
                            size="sm"
                            @click="signIn(server)"
                            ><LogIn class="h-4 w-4" /> Sign in</Button
                        >
                    </template>
                    <Button variant="outline" size="sm" @click="edit(server)"
                        >Edit</Button
                    >
                    <Button
                        variant="destructive"
                        size="sm"
                        @click="remove(server)"
                        >Remove</Button
                    >
                </div>
            </div>
        </div>

        <form class="space-y-4 rounded-lg border p-4" @submit.prevent="submit">
            <Heading
                variant="small"
                :title="editing ? `Edit ${editing.name}` : 'Add a server'"
            />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    placeholder="filesystem"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="transport">Transport</Label>
                <select
                    id="transport"
                    v-model="form.transport"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-input/30"
                >
                    <option value="stdio" :disabled="!stdioEnabled">
                        Local command (stdio)
                    </option>
                    <option value="http">Remote (HTTP)</option>
                </select>
                <InputError :message="form.errors.transport" />
            </div>

            <template v-if="form.transport === 'stdio'">
                <div class="grid gap-2">
                    <Label for="command">Command</Label>
                    <Input
                        id="command"
                        v-model="form.command"
                        placeholder="npx"
                    />
                    <InputError :message="form.errors.command" />
                </div>
                <div class="grid gap-2">
                    <Label for="args">Arguments (one per line)</Label>
                    <textarea
                        id="args"
                        v-model="form.args"
                        rows="3"
                        :class="textareaClass"
                    />
                    <InputError :message="form.errors.args" />
                </div>
                <div class="grid gap-2">
                    <Label for="env">Environment (KEY=value per line)</Label>
                    <textarea
                        id="env"
                        v-model="form.env"
                        rows="2"
                        :class="textareaClass"
                        :placeholder="
                            editing
                                ? 'Leave blank to keep the saved values'
                                : ''
                        "
                    />
                    <InputError :message="form.errors.env" />
                </div>
            </template>

            <template v-else>
                <div class="grid gap-2">
                    <Label for="url">URL</Label>
                    <Input
                        id="url"
                        v-model="form.url"
                        type="url"
                        placeholder="https://example.com/mcp"
                    />
                    <InputError :message="form.errors.url" />
                </div>
                <div class="grid gap-2">
                    <Label for="headers">Headers (Name: value per line)</Label>
                    <textarea
                        id="headers"
                        v-model="form.headers"
                        rows="2"
                        :class="textareaClass"
                        :placeholder="
                            editing
                                ? 'Leave blank to keep the saved values'
                                : 'Authorization: Bearer ...'
                        "
                    />
                    <InputError :message="form.errors.headers" />
                </div>
                <details
                    class="rounded-md border p-3 text-sm"
                    :open="!!(form.oauth_client_id || form.oauth_scope)"
                >
                    <summary class="cursor-pointer font-medium">
                        OAuth sign-in (optional)
                    </summary>
                    <p class="mt-2 text-muted-foreground">
                        Servers that need you to sign in work without these:
                        save the server, then use <strong>Sign in</strong>. Only
                        fill them in if the server's provider gave you a client
                        ID.
                    </p>
                    <div class="mt-3 grid gap-3">
                        <div class="grid gap-2">
                            <Label for="oauth_client_id">Client ID</Label>
                            <Input
                                id="oauth_client_id"
                                v-model="form.oauth_client_id"
                            />
                            <InputError
                                :message="form.errors.oauth_client_id"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="oauth_client_secret"
                                >Client secret</Label
                            >
                            <Input
                                id="oauth_client_secret"
                                v-model="form.oauth_client_secret"
                                type="password"
                                autocomplete="off"
                                :placeholder="
                                    editing
                                        ? 'Leave blank to keep the saved value'
                                        : ''
                                "
                            />
                            <InputError
                                :message="form.errors.oauth_client_secret"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="oauth_scope">Scopes</Label>
                            <Input
                                id="oauth_scope"
                                v-model="form.oauth_scope"
                                placeholder="mcp:use"
                            />
                            <InputError :message="form.errors.oauth_scope" />
                        </div>
                    </div>
                </details>
            </template>

            <Label class="flex items-center gap-2">
                <Checkbox
                    :model-value="form.enabled"
                    @update:model-value="form.enabled = $event === true"
                />
                Enabled
            </Label>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">
                    {{ editing ? 'Save' : 'Add server' }}
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
