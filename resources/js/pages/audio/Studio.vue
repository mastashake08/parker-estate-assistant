<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { AudioLines, FileMusic, Mic, Music, RefreshCw } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AudioGenerationController from '@/actions/App/Http/Controllers/Audio/AudioGenerationController';
import MusicModelController from '@/actions/App/Http/Controllers/Audio/MusicModelController';
import GenerationCard from '@/components/audio/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/audio';
import type {
    AudioGeneration,
    AudioKind,
    DiffSingerStatus,
    MusicGenStatus,
    SpeechServer,
} from '@/types';

const props = defineProps<{
    generations: AudioGeneration[];
    /** Deferred: the engines are asked for their state after the page loads. */
    speech?: SpeechServer[];
    music?: MusicGenStatus;
    singing?: DiffSingerStatus;
    midiModels?: string[];
    limits: {
        speechCharacters: number;
        musicSeconds: number;
        midiBars: number;
    };
    defaults: {
        musicModel: string;
        midiModel: string;
        singingVoice: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Audio studio', href: index() }],
    },
});

const page = usePage();

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm dark:bg-input/30';

const tabs: {
    kind: AudioKind;
    label: string;
    icon: typeof Mic;
    description: string;
}[] = [
    {
        kind: 'speech',
        label: 'Speech',
        icon: Mic,
        description: 'Read text aloud with a Phonon voice.',
    },
    {
        kind: 'music',
        label: 'Music',
        icon: Music,
        description: 'Describe a piece and MusicGen plays it.',
    },
    {
        kind: 'midi',
        label: 'MIDI',
        icon: FileMusic,
        description:
            'Llama 3 writes multi-track MIDI you can play on any synth.',
    },
    {
        kind: 'vocals',
        label: 'Vocals',
        icon: AudioLines,
        description:
            'Llama 3 writes lyrics and a melody, and DiffSinger sings them.',
    },
];

const engines = computed(() => props.speech !== undefined);
const onlineServers = computed(() =>
    (props.speech ?? []).filter((server) => server.online),
);
const voices = computed(() => props.singing?.voices ?? []);

const form = useForm({
    kind: 'speech' as AudioKind,
    prompt: '',
    server: '',
    voice: '',
    speaker: '',
    model: '',
    duration: 10,
    bars: 8,
    // Empty lets the model choose.
    tempo: '' as number | '',
});

const tab = computed(() =>
    tabs.find((candidate) => candidate.kind === form.kind)!,
);
const server = computed(() =>
    onlineServers.value.find((candidate) => candidate.url === form.server),
);
const singingVoice = computed(() =>
    voices.value.find((voice) => voice.name === form.voice),
);

const choose = (kind: AudioKind) => {
    form.kind = kind;
    form.clearErrors();
    form.voice = '';
    form.speaker = '';
    form.model = '';

    if (kind === 'vocals') {
        form.voice = props.defaults.singingVoice ?? voices.value[0]?.name ?? '';
    }
};

// Pick the first engine of each kind once their states have arrived.
watch(
    () => [props.speech, props.singing] as const,
    () => {
        form.server ||= onlineServers.value[0]?.url ?? '';

        if (form.kind === 'vocals') {
            form.voice ||=
                props.defaults.singingVoice ?? voices.value[0]?.name ?? '';
        }
    },
    { immediate: true },
);

const placeholders: Record<AudioKind, string> = {
    speech: 'Welcome home. The lights are on and dinner is at seven.',
    music: 'A warm lo-fi hip hop beat with mellow Rhodes chords and vinyl crackle',
    midi: 'A funky groove in E minor with slap bass, clavinet chords, a brass hook and a tight drum beat',
    vocals: 'An upbeat pop chorus about coming home after a long trip',
};

const canSubmit = computed(() => {
    if (!form.prompt.trim() || form.processing) {
        return false;
    }

    return form.kind === 'speech'
        ? !!form.server
        : form.kind === 'vocals'
          ? !!form.voice
          : true;
});

const submit = () => {
    form.transform((data) => {
        const base = { kind: data.kind, prompt: data.prompt };

        switch (data.kind) {
            case 'speech':
                return {
                    ...base,
                    server: data.server,
                    voice: data.voice || null,
                };
            case 'music':
                return {
                    ...base,
                    model: data.model || null,
                    duration: data.duration,
                };
            case 'midi':
                return {
                    ...base,
                    model: data.model || null,
                    bars: data.bars,
                    tempo: data.tempo || null,
                };
            case 'vocals':
                return {
                    ...base,
                    voice: data.voice,
                    speaker: data.speaker || null,
                    model: data.model || null,
                    bars: data.bars,
                    tempo: data.tempo || null,
                };
        }
    });

    form.post(AudioGenerationController.store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt'),
    });
};

const refreshEngines = () =>
    router.reload({ only: ['speech', 'music', 'singing', 'midiModels'] });

const loadMusicModel = (model: string) =>
    router.post(
        MusicModelController.store.url(),
        { model },
        { preserveScroll: true, onSuccess: () => refreshEngines() },
    );

// While MusicGen loads a model, check on it every few seconds.
let polling: ReturnType<typeof setInterval> | null = null;

watch(
    () => props.music?.loading,
    (loading) => {
        if (loading && !polling) {
            polling = setInterval(
                () => router.reload({ only: ['music'] }),
                5000,
            );
        } else if (!loading && polling) {
            clearInterval(polling);
            polling = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => polling && clearInterval(polling));

// Generations update the library as they start, finish or fail.
useEcho(
    `App.Models.User.${page.props.auth.user.id}`,
    '.audio.generation',
    () => {
        router.reload({ only: ['generations'] });
    },
);

const filter = ref<AudioKind | 'all'>('all');
const shown = computed(() =>
    filter.value === 'all'
        ? props.generations
        : props.generations.filter(
              (generation) => generation.kind === filter.value,
          ),
);
</script>

<template>
    <Head title="Audio studio" />

    <div class="mx-auto flex max-w-4xl flex-col gap-8 p-4">
        <Heading
            title="Audio studio"
            description="Make speech, music, MIDI stems and sung vocals with local models, and play them right here"
        />

        <section class="space-y-3" aria-labelledby="engines">
            <div class="flex items-center justify-between">
                <h3 id="engines" class="text-sm font-medium">Engines</h3>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="!engines"
                    @click="refreshEngines"
                >
                    <RefreshCw class="h-3.5 w-3.5" /> Refresh
                </Button>
            </div>

            <div
                v-if="!engines"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Spinner class="h-4 w-4" /> Checking the speech, music and
                singing engines…
            </div>

            <div v-else class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1 rounded-lg border p-3 text-sm">
                    <div class="flex items-center gap-2 font-medium">
                        <Mic class="h-4 w-4" /> Phonon speech
                    </div>
                    <p
                        v-if="!speech?.length"
                        class="text-xs text-muted-foreground"
                    >
                        No servers are configured (PHONON_URLS).
                    </p>
                    <p
                        v-for="item in speech"
                        :key="item.url"
                        class="flex flex-wrap items-center gap-2 text-xs"
                    >
                        <Badge
                            :variant="item.online ? 'secondary' : 'destructive'"
                            >{{ item.online ? 'online' : 'offline' }}</Badge
                        >
                        <span class="font-mono">{{
                            item.model ?? item.url
                        }}</span>
                        <span v-if="item.online" class="text-muted-foreground"
                            >{{ item.voices.length }} voices ·
                            {{ item.languages.join(', ') }}</span
                        >
                        <span v-else class="text-muted-foreground">{{
                            item.error
                        }}</span>
                    </p>
                </div>

                <div class="space-y-2 rounded-lg border p-3 text-sm">
                    <div class="flex items-center gap-2 font-medium">
                        <Music class="h-4 w-4" /> MusicGen
                    </div>
                    <p
                        v-if="!music?.online"
                        class="text-xs text-muted-foreground"
                    >
                        <Badge variant="destructive">offline</Badge>
                        {{ music?.error }}
                    </p>
                    <template v-else>
                        <p class="flex flex-wrap items-center gap-2 text-xs">
                            <Badge variant="secondary">{{
                                music.device
                            }}</Badge>
                            <span
                                v-if="music.loading"
                                class="flex items-center gap-1"
                                ><Spinner class="h-3 w-3" /> Loading
                                {{ music.loading }}…</span
                            >
                            <span v-else-if="music.loaded" class="font-mono"
                                >{{ music.loaded }} loaded</span
                            >
                            <span v-else class="text-muted-foreground"
                                >No model loaded yet</span
                            >
                        </p>
                        <p v-if="music.error" class="text-xs text-destructive">
                            {{ music.error }}
                        </p>
                        <label class="flex items-center gap-2 text-xs">
                            <span class="shrink-0">Load</span>
                            <select
                                class="h-8 min-w-0 flex-1 rounded-md border border-input bg-transparent px-2 dark:bg-input/30"
                                :disabled="!!music.loading"
                                :value="music.loaded ?? ''"
                                @change="
                                    loadMusicModel(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="" disabled>
                                    Choose a model
                                </option>
                                <option
                                    v-for="model in music.models"
                                    :key="model"
                                    :value="model"
                                >
                                    {{ model }}
                                </option>
                            </select>
                        </label>
                    </template>
                </div>

                <div class="space-y-1 rounded-lg border p-3 text-sm">
                    <div class="flex items-center gap-2 font-medium">
                        <AudioLines class="h-4 w-4" /> DiffSinger vocals
                    </div>
                    <p
                        v-if="!singing?.online"
                        class="text-xs text-muted-foreground"
                    >
                        <Badge variant="destructive">offline</Badge>
                        {{ singing?.error }}
                    </p>
                    <p
                        v-else-if="!voices.length"
                        class="text-xs text-muted-foreground"
                    >
                        No voices are installed. Add DiffSinger experiments to
                        the checkpoints folder.
                    </p>
                    <p
                        v-for="voice in voices"
                        :key="voice.name"
                        class="flex flex-wrap items-center gap-2 text-xs"
                    >
                        <span class="font-mono">{{ voice.name }}</span>
                        <span class="text-muted-foreground">{{
                            voice.language ?? voice.languages.join(', ')
                        }}</span>
                        <Badge v-if="voice.variance" variant="outline"
                            >variance</Badge
                        >
                        <span
                            v-if="voice.speakers.length"
                            class="text-muted-foreground"
                            >{{ voice.speakers.length }} speakers</span
                        >
                    </p>
                </div>

                <div class="space-y-1 rounded-lg border p-3 text-sm">
                    <div class="flex items-center gap-2 font-medium">
                        <FileMusic class="h-4 w-4" /> Composer (Ollama)
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Writes MIDI and lyrics with
                        <span class="font-mono">{{ defaults.midiModel }}</span>
                        unless you pick another.
                        <template v-if="midiModels && !midiModels.length">
                            Ollama could not be reached.</template
                        >
                    </p>
                </div>
            </div>
        </section>

        <form class="space-y-5 rounded-lg border p-4" @submit.prevent="submit">
            <div class="flex flex-wrap gap-2" role="tablist">
                <Button
                    v-for="item in tabs"
                    :key="item.kind"
                    type="button"
                    role="tab"
                    size="sm"
                    :aria-selected="form.kind === item.kind"
                    :variant="form.kind === item.kind ? 'default' : 'outline'"
                    @click="choose(item.kind)"
                >
                    <component :is="item.icon" class="h-4 w-4" />
                    {{ item.label }}
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">{{ tab.description }}</p>

            <div class="grid gap-2">
                <Label for="prompt">{{
                    form.kind === 'speech' ? 'Text to speak' : 'Describe it'
                }}</Label>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    rows="4"
                    required
                    :maxlength="
                        form.kind === 'speech' ? limits.speechCharacters : 2000
                    "
                    :class="textareaClass"
                    :placeholder="placeholders[form.kind]"
                />
                <InputError :message="form.errors.prompt" />
            </div>

            <div
                v-if="form.kind === 'speech'"
                class="grid gap-4 sm:grid-cols-2"
            >
                <div class="grid gap-2">
                    <Label for="server">Model</Label>
                    <select
                        id="server"
                        v-model="form.server"
                        :class="selectClass"
                        :disabled="!onlineServers.length"
                    >
                        <option v-if="!onlineServers.length" value="">
                            No speech server is online
                        </option>
                        <option
                            v-for="item in onlineServers"
                            :key="item.url"
                            :value="item.url"
                        >
                            {{ item.model ?? item.url }}
                        </option>
                    </select>
                    <InputError :message="form.errors.server" />
                </div>
                <div class="grid gap-2">
                    <Label for="voice">Voice</Label>
                    <select
                        id="voice"
                        v-model="form.voice"
                        :class="selectClass"
                    >
                        <option value="">Default</option>
                        <option
                            v-for="voice in server?.voices ?? []"
                            :key="voice"
                            :value="voice"
                        >
                            {{ voice }}
                        </option>
                    </select>
                    <InputError :message="form.errors.voice" />
                </div>
            </div>

            <div v-if="form.kind === 'music'" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="music-model">Model</Label>
                    <select
                        id="music-model"
                        v-model="form.model"
                        :class="selectClass"
                    >
                        <option value="">
                            {{ music?.loaded ?? defaults.musicModel }} (loaded
                            or default)
                        </option>
                        <option
                            v-for="model in music?.models ?? []"
                            :key="model"
                            :value="model"
                        >
                            {{ model }}
                        </option>
                    </select>
                    <InputError :message="form.errors.model" />
                </div>
                <div class="grid gap-2">
                    <Label for="duration"
                        >Length: {{ form.duration }} seconds</Label
                    >
                    <input
                        id="duration"
                        v-model.number="form.duration"
                        type="range"
                        min="1"
                        :max="limits.musicSeconds"
                        class="accent-primary"
                    />
                    <InputError :message="form.errors.duration" />
                </div>
            </div>

            <div
                v-if="form.kind === 'midi' || form.kind === 'vocals'"
                class="grid gap-4 sm:grid-cols-3"
            >
                <div
                    v-if="form.kind === 'vocals'"
                    class="grid gap-2 sm:col-span-3 sm:grid-cols-2 sm:gap-4"
                >
                    <div class="grid gap-2">
                        <Label for="singing-voice">Singing voice</Label>
                        <select
                            id="singing-voice"
                            v-model="form.voice"
                            :class="selectClass"
                            :disabled="!voices.length"
                        >
                            <option v-if="!voices.length" value="">
                                No DiffSinger voice is installed
                            </option>
                            <option
                                v-for="voice in voices"
                                :key="voice.name"
                                :value="voice.name"
                            >
                                {{ voice.name
                                }}{{
                                    voice.language ? ` (${voice.language})` : ''
                                }}
                            </option>
                        </select>
                        <InputError :message="form.errors.voice" />
                    </div>
                    <div
                        v-if="singingVoice?.speakers.length"
                        class="grid gap-2"
                    >
                        <Label for="speaker">Speaker</Label>
                        <select
                            id="speaker"
                            v-model="form.speaker"
                            :class="selectClass"
                        >
                            <option value="">
                                {{ singingVoice.speaker ?? 'Default' }}
                            </option>
                            <option
                                v-for="speaker in singingVoice.speakers"
                                :key="speaker"
                                :value="speaker"
                            >
                                {{ speaker }}
                            </option>
                        </select>
                        <InputError :message="form.errors.speaker" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="composer-model">Language model</Label>
                    <select
                        id="composer-model"
                        v-model="form.model"
                        :class="selectClass"
                    >
                        <option value="">
                            {{ defaults.midiModel }} (default)
                        </option>
                        <option
                            v-for="model in (midiModels ?? []).filter(
                                (name) => name !== defaults.midiModel,
                            )"
                            :key="model"
                            :value="model"
                        >
                            {{ model }}
                        </option>
                    </select>
                    <InputError :message="form.errors.model" />
                </div>
                <div class="grid gap-2">
                    <Label for="bars">Bars</Label>
                    <Input
                        id="bars"
                        v-model.number="form.bars"
                        type="number"
                        min="1"
                        :max="limits.midiBars"
                        required
                    />
                    <InputError :message="form.errors.bars" />
                </div>
                <div class="grid gap-2">
                    <Label for="tempo">Tempo (BPM)</Label>
                    <Input
                        id="tempo"
                        v-model.number="form.tempo"
                        type="number"
                        min="40"
                        max="220"
                        placeholder="Let the model choose"
                    />
                    <InputError :message="form.errors.tempo" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="!canSubmit">
                    <Spinner v-if="form.processing" class="h-4 w-4" />
                    Generate
                </Button>
                <p
                    v-if="form.kind === 'music'"
                    class="text-xs text-muted-foreground"
                >
                    On a CPU, MusicGen can take several minutes for 30 seconds
                    of music.
                </p>
            </div>
        </form>

        <section class="space-y-3" aria-labelledby="library">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="library" class="text-sm font-medium">Library</h3>
                <div class="flex flex-wrap gap-1">
                    <Button
                        v-for="item in [{ kind: 'all', label: 'All' }, ...tabs]"
                        :key="item.kind"
                        size="sm"
                        :variant="filter === item.kind ? 'secondary' : 'ghost'"
                        @click="filter = item.kind as AudioKind | 'all'"
                        >{{ item.label }}</Button
                    >
                </div>
            </div>

            <p v-if="!shown.length" class="text-sm text-muted-foreground">
                Nothing here yet. Make something above.
            </p>

            <GenerationCard
                v-for="generation in shown"
                :key="generation.id"
                :generation="generation"
            />
        </section>
    </div>
</template>
