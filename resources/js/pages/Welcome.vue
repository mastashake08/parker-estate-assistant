<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    Cpu,
    FileText,
    Globe,
    Lock,
    Mail,
    Plug,
    Sparkles,
    Wrench,
} from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const name = usePage().props.name;

const features = [
    {
        icon: Cpu,
        title: 'Runs on your machine',
        detail: 'Replies come from local models through Ollama. Your conversations never leave your computer.',
    },
    {
        icon: Globe,
        title: 'Searches the web',
        detail: 'It searches and reads pages in a headless browser, and links every source it used.',
    },
    {
        icon: FileText,
        title: 'Reads your documents',
        detail: 'Attach PDFs, Word files, text, Markdown or CSV and ask about them for the rest of the chat.',
    },
    {
        icon: Mail,
        title: 'Email and texts, with approval',
        detail: 'It drafts emails and text messages, but nothing is sent until you approve it.',
    },
    {
        icon: Plug,
        title: 'Connects to MCP servers',
        detail: 'Add local or remote Model Context Protocol servers and their tools are ready to use.',
    },
    {
        icon: Sparkles,
        title: 'Learns your skills',
        detail: 'Write reusable instructions once, and the assistant follows them when a task matches.',
    },
];

const steps = [
    {
        title: 'Pull a model',
        detail: 'Install Ollama and pull a model that supports tools, such as llama3.2:3b.',
    },
    {
        title: 'Create your account',
        detail: 'Sign up, then choose which tools and MCP servers the assistant may use.',
    },
    {
        title: 'Start asking',
        detail: 'Chat, attach documents, and approve anything it wants to send.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="min-h-screen bg-background text-foreground">
        <header
            class="sticky top-0 z-10 border-b border-border/60 bg-background/80 backdrop-blur"
        >
            <div
                class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6"
            >
                <div class="flex items-center gap-2 font-semibold">
                    <div
                        class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                    >
                        <AppLogoIcon class="size-5 fill-current" />
                    </div>
                    <span class="hidden sm:inline">{{ name }}</span>
                </div>
                <nav class="flex items-center gap-2">
                    <Button v-if="$page.props.auth.user" as-child>
                        <Link :href="dashboard()"
                            >Dashboard <ArrowRight class="h-4 w-4"
                        /></Link>
                    </Button>
                    <template v-else>
                        <Button variant="ghost" as-child>
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button as-child>
                            <Link :href="register()">Get started</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <main>
            <section class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute inset-x-0 -top-40 -z-0 flex justify-center"
                    aria-hidden="true"
                >
                    <div
                        class="h-[480px] w-[880px] rounded-full bg-gradient-to-tr from-primary/15 via-primary/5 to-transparent blur-3xl"
                    />
                </div>

                <div
                    class="relative mx-auto grid max-w-6xl items-center gap-12 px-6 py-20 lg:grid-cols-2 lg:py-28"
                >
                    <div
                        class="space-y-6 transition-opacity duration-700 starting:opacity-0"
                    >
                        <span
                            class="inline-flex items-center gap-2 rounded-full border bg-card px-3 py-1 text-xs text-muted-foreground"
                        >
                            <Lock class="h-3 w-3" /> Private by design
                        </span>
                        <h1
                            class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                        >
                            Your personal AI assistant, running on your own
                            computer.
                        </h1>
                        <p class="max-w-xl text-lg text-muted-foreground">
                            Ask questions, research the web, analyze documents
                            and send messages, with local models that keep
                            everything you say to yourself.
                        </p>
                        <div class="flex flex-wrap gap-3">
                            <Button
                                v-if="$page.props.auth.user"
                                size="lg"
                                as-child
                            >
                                <Link :href="dashboard()"
                                    >Open your dashboard
                                    <ArrowRight class="h-4 w-4"
                                /></Link>
                            </Button>
                            <template v-else>
                                <Button size="lg" as-child>
                                    <Link :href="register()"
                                        >Create your account
                                        <ArrowRight class="h-4 w-4"
                                    /></Link>
                                </Button>
                                <Button size="lg" variant="outline" as-child>
                                    <Link :href="login()">Log in</Link>
                                </Button>
                            </template>
                        </div>
                    </div>

                    <div
                        class="rounded-2xl border bg-card p-4 shadow-xl shadow-primary/5 transition-all duration-700 starting:translate-y-4 starting:opacity-0"
                        aria-hidden="true"
                    >
                        <div class="mb-4 flex items-center gap-1.5 px-1">
                            <span class="h-2.5 w-2.5 rounded-full bg-muted" />
                            <span class="h-2.5 w-2.5 rounded-full bg-muted" />
                            <span class="h-2.5 w-2.5 rounded-full bg-muted" />
                        </div>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-end">
                                <p
                                    class="max-w-[85%] rounded-lg bg-primary px-4 py-2 text-primary-foreground"
                                >
                                    Summarize the lease I attached and text Sam
                                    the move-out date.
                                </p>
                            </div>
                            <div
                                class="max-w-[85%] space-y-2 rounded-lg bg-muted px-4 py-3"
                            >
                                <p
                                    class="flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <Wrench class="h-3 w-3" /> Used
                                    read_document
                                </p>
                                <p>
                                    The lease runs 12 months at
                                    <strong>$1,450/month</strong>. You must give
                                    <strong>60 days' notice</strong>, and
                                    move-out is <strong>June 30</strong>.
                                </p>
                            </div>
                            <div
                                class="max-w-[85%] rounded-lg border border-amber-500/40 bg-amber-500/5 px-4 py-3"
                            >
                                <p class="font-medium">Waiting for approval</p>
                                <p class="text-xs text-muted-foreground">
                                    Send a text message to +1 502 555 0123
                                </p>
                                <div class="mt-2 flex gap-2">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-md bg-primary px-2.5 py-1 text-xs text-primary-foreground"
                                        ><Check class="h-3 w-3" /> Approve</span
                                    >
                                    <span
                                        class="rounded-md border px-2.5 py-1 text-xs"
                                        >Deny</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-t bg-muted/30">
                <div class="mx-auto max-w-6xl px-6 py-20">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Everything a personal assistant should do
                    </h2>
                    <p class="mt-2 text-muted-foreground">
                        Built in, and you choose which tools it may use.
                    </p>
                    <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="feature in features"
                            :key="feature.title"
                            class="rounded-xl border bg-card p-6 transition-colors hover:border-primary/40"
                        >
                            <div
                                class="mb-4 flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
                            >
                                <component :is="feature.icon" class="h-5 w-5" />
                            </div>
                            <h3 class="font-medium">{{ feature.title }}</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ feature.detail }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-t">
                <div class="mx-auto max-w-6xl px-6 py-20">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        Up and running in three steps
                    </h2>
                    <ol class="mt-10 grid gap-8 md:grid-cols-3">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.title"
                            class="flex gap-4"
                        >
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border bg-card text-sm font-semibold"
                                >{{ index + 1 }}</span
                            >
                            <div>
                                <h3 class="font-medium">{{ step.title }}</h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ step.detail }}
                                </p>
                            </div>
                        </li>
                    </ol>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-8 text-sm text-muted-foreground"
            >
                <p>{{ name }}</p>
                <a
                    href="https://github.com/mastashake08/parker-estate-assistant"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="underline-offset-4 hover:text-foreground hover:underline"
                    >View on GitHub</a
                >
            </div>
        </footer>
    </div>
</template>
