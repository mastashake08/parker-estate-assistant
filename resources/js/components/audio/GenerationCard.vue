<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AudioLines, FileMusic, Mic, Music, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import AudioGenerationController from '@/actions/App/Http/Controllers/Audio/AudioGenerationController';
import MidiStemPlayer from '@/components/audio/MidiStemPlayer.vue';
import WavPlayer from '@/components/audio/WavPlayer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type {
    AudioGeneration,
    AudioGenerationStatus,
    AudioKind,
} from '@/types';

const props = defineProps<{ generation: AudioGeneration }>();

const kinds: Record<AudioKind, { label: string; icon: typeof Mic }> = {
    speech: { label: 'Speech', icon: Mic },
    music: { label: 'Music', icon: Music },
    midi: { label: 'MIDI', icon: FileMusic },
    vocals: { label: 'Vocals', icon: AudioLines },
};

const statusLabels: Record<AudioGenerationStatus, string> = {
    pending: 'Queued',
    running: 'Generating',
    completed: 'Ready',
    failed: 'Failed',
};

const kind = computed(() => kinds[props.generation.kind]);
const working = computed(() =>
    ['pending', 'running'].includes(props.generation.status),
);
const ready = computed(() => props.generation.status === 'completed');

const fileUrl = (query: Record<string, string | number> = {}) =>
    AudioGenerationController.show.url(props.generation.id, { query });

const stemUrl = (stem?: number) =>
    fileUrl(stem === undefined ? { download: 1 } : { stem, download: 1 });

const formatTime = (value: string) =>
    new Date(value).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

const remove = () => {
    if (confirm(`Delete "${props.generation.title}"?`)) {
        router.delete(
            AudioGenerationController.destroy.url(props.generation.id),
            { preserveScroll: true },
        );
    }
};
</script>

<template>
    <article class="space-y-3 rounded-lg border p-4">
        <header class="flex items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center gap-2 font-medium">
                    <component
                        :is="kind.icon"
                        class="h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <span class="truncate">{{ generation.title }}</span>
                    <Badge variant="outline">{{ kind.label }}</Badge>
                    <Badge
                        v-if="!ready"
                        :variant="
                            generation.status === 'failed'
                                ? 'destructive'
                                : 'secondary'
                        "
                    >
                        <Spinner v-if="working" class="h-3 w-3" />
                        {{ statusLabels[generation.status] }}
                    </Badge>
                </div>
                <p class="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                    <span>{{ formatTime(generation.created_at) }}</span>
                    <span v-if="generation.model">{{ generation.model }}</span>
                    <span v-if="generation.options.source === 'browser'"
                        >Made in the browser ({{
                            generation.options.device
                        }})</span
                    >
                    <span v-if="generation.options.voice"
                        >Voice: {{ generation.options.voice }}</span
                    >
                    <span v-if="generation.options.key">{{
                        generation.options.key
                    }}</span>
                    <span v-if="ready && generation.options.tempo"
                        >{{ generation.options.tempo }} BPM</span
                    >
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                aria-label="Delete"
                @click="remove"
            >
                <Trash2 class="h-4 w-4" />
            </Button>
        </header>

        <p
            v-if="generation.kind !== 'vocals' || !ready"
            class="line-clamp-2 text-sm whitespace-pre-line text-muted-foreground"
        >
            {{ generation.prompt }}
        </p>

        <p v-if="generation.error" class="text-sm text-destructive">
            {{ generation.error }}
        </p>

        <template v-if="ready">
            <WavPlayer
                v-if="generation.kind !== 'midi'"
                :src="fileUrl()"
                :download-url="fileUrl({ download: 1 })"
                :duration="generation.duration"
            />

            <template v-if="generation.kind === 'vocals'">
                <blockquote
                    v-if="generation.options.lyrics"
                    class="border-l-2 pl-3 text-sm whitespace-pre-line"
                >
                    {{ generation.options.lyrics }}
                </blockquote>
                <p
                    v-if="generation.options.unknown_words?.length"
                    class="text-xs text-muted-foreground"
                >
                    The voice did not know
                    {{ generation.options.unknown_words.join(', ') }}, so it
                    sang "ah" instead.
                </p>
            </template>

            <MidiStemPlayer
                v-if="generation.stems?.length"
                :stems="generation.stems"
                :tempo="generation.options.tempo ?? 120"
                :beats-per-bar="generation.options.beats_per_bar ?? 4"
                :download-url="stemUrl"
            />
        </template>

        <a
            v-if="generation.has_score"
            :href="fileUrl({ format: 'ds', download: 1 })"
            class="inline-flex items-center gap-1 text-xs text-muted-foreground underline-offset-4 hover:text-primary hover:underline"
            ><FileMusic class="h-3.5 w-3.5" /> DiffSinger score (.ds)</a
        >
    </article>
</template>
