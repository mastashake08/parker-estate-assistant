<script setup lang="ts">
import { Download, Pause, Play, Repeat } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { audioContext, loadAudio, peaks } from '@/lib/audio/context';

const props = defineProps<{
    src: string;
    downloadUrl: string;
    /** Seconds, known before the file is loaded. */
    duration?: number | null;
}>();

const buffer = ref<AudioBuffer | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const playing = ref(false);
const loop = ref(false);
const volume = ref(0.9);
const position = ref(0);
const level = ref(0);

const canvas = ref<HTMLCanvasElement | null>(null);

let source: AudioBufferSourceNode | null = null;
let gain: GainNode | null = null;
let analyser: AnalyserNode | null = null;
let startedAt = 0;
let offset = 0;
let frame = 0;
let resize: ResizeObserver | null = null;

/** The waveform at a resolution finer than any canvas will need, worked out once per file. */
const PEAKS = 600;
let waveform: Float32Array | null = null;

const length = computed(() => buffer.value?.duration ?? props.duration ?? 0);

const formatTime = (seconds: number) => {
    const whole = Math.max(0, Math.floor(seconds));

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
};

/** Draw the waveform's peaks, with the played part in the foreground colour. */
const draw = () => {
    const element = canvas.value;

    if (!element) {
        return;
    }

    const ratio = window.devicePixelRatio || 1;
    const width = element.clientWidth;
    const height = element.clientHeight;
    element.width = width * ratio;
    element.height = height * ratio;

    const context = element.getContext('2d');

    if (!context) {
        return;
    }

    context.scale(ratio, ratio);
    context.clearRect(0, 0, width, height);

    const style = getComputedStyle(element);
    const played = style.getPropertyValue('--played').trim() || style.color;
    const unplayed = style.getPropertyValue('--unplayed').trim() || style.color;
    const progress = length.value ? position.value / length.value : 0;
    const bars = Math.max(1, Math.floor(width / 3));

    if (!buffer.value) {
        context.fillStyle = unplayed;
        context.fillRect(0, height / 2 - 0.5, width, 1);

        return;
    }

    waveform ??= peaks(buffer.value, PEAKS);

    for (let bar = 0; bar < bars; bar++) {
        const peak = waveform[Math.floor((bar / bars) * PEAKS)];
        const barHeight = Math.max(1, Math.min(1, peak) * height);
        context.fillStyle = bar / bars <= progress ? played : unplayed;
        context.fillRect(bar * 3, (height - barHeight) / 2, 2, barHeight);
    }
};

const tick = () => {
    if (playing.value && source && buffer.value) {
        const elapsed = source.context.currentTime - startedAt + offset;
        position.value = loop.value
            ? elapsed % buffer.value.duration
            : Math.min(elapsed, buffer.value.duration);

        if (analyser) {
            const samples = new Float32Array(analyser.fftSize);
            analyser.getFloatTimeDomainData(samples);
            level.value = Math.min(
                1,
                Math.sqrt(
                    samples.reduce((sum, sample) => sum + sample * sample, 0) /
                        samples.length,
                ) * 3,
            );
        }
        draw();
    }

    frame = requestAnimationFrame(tick);
};

const stopSource = () => {
    if (source) {
        source.onended = null;
        source.stop();
        source.disconnect();
        source = null;
    }
};

const start = async (at: number) => {
    const context = await audioContext();

    loading.value = !buffer.value;
    error.value = null;

    try {
        if (!buffer.value) {
            buffer.value = await loadAudio(props.src);
            draw();
        }
    } catch {
        error.value = 'The audio could not be loaded.';

        return;
    } finally {
        loading.value = false;
    }

    stopSource();

    gain ??= context.createGain();
    analyser ??= context.createAnalyser();
    gain.gain.value = volume.value;
    gain.connect(analyser);
    analyser.connect(context.destination);

    source = context.createBufferSource();
    source.buffer = buffer.value;
    source.loop = loop.value;
    source.connect(gain);
    source.onended = () => {
        playing.value = false;
        position.value = 0;
        offset = 0;
        level.value = 0;
        draw();
    };

    offset = Math.min(at, buffer.value.duration - 0.01);
    startedAt = context.currentTime;
    source.start(0, offset);
    playing.value = true;
};

const toggle = () => {
    if (playing.value) {
        offset = position.value;
        stopSource();
        playing.value = false;
        level.value = 0;
    } else {
        start(position.value >= length.value ? 0 : position.value);
    }
};

const seek = (event: MouseEvent) => {
    if (!length.value) {
        return;
    }

    const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const at = ((event.clientX - bounds.left) / bounds.width) * length.value;

    position.value = at;
    draw();

    if (playing.value) {
        start(at);
    } else {
        offset = at;
    }
};

watch(volume, (value) => {
    if (gain) {
        gain.gain.value = value;
    }
});

watch(loop, (value) => {
    if (source) {
        source.loop = value;
    }
});

onMounted(() => {
    frame = requestAnimationFrame(tick);

    if (canvas.value) {
        resize = new ResizeObserver(draw);
        resize.observe(canvas.value);
    }
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    resize?.disconnect();
    stopSource();
    gain?.disconnect();
    analyser?.disconnect();
});
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center gap-3">
            <Button
                size="icon"
                variant="secondary"
                :disabled="loading"
                :aria-label="playing ? 'Pause' : 'Play'"
                @click="toggle"
            >
                <Pause v-if="playing" class="h-4 w-4" />
                <Play v-else class="h-4 w-4" />
            </Button>

            <div
                class="relative h-12 min-w-0 flex-1 cursor-pointer text-muted-foreground [--played:var(--color-primary)] [--unplayed:color-mix(in_oklab,currentColor_40%,transparent)]"
                role="slider"
                aria-label="Position"
                :aria-valuemax="Math.round(length)"
                :aria-valuenow="Math.round(position)"
                @click="seek"
            >
                <canvas ref="canvas" class="h-full w-full" />
            </div>

            <span
                class="w-20 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
            >
                {{ formatTime(position) }} / {{ formatTime(length) }}
            </span>
        </div>

        <div
            class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
        >
            <Button
                size="sm"
                :variant="loop ? 'secondary' : 'ghost'"
                :aria-pressed="loop"
                @click="loop = !loop"
                ><Repeat class="h-3.5 w-3.5" /> Loop</Button
            >
            <label class="flex items-center gap-2">
                Volume
                <input
                    v-model.number="volume"
                    type="range"
                    min="0"
                    max="1"
                    step="0.01"
                    class="w-24 accent-primary"
                />
            </label>
            <div
                class="h-1.5 w-16 overflow-hidden rounded-full bg-muted"
                aria-hidden="true"
            >
                <div
                    class="h-full bg-primary transition-[width] duration-75"
                    :style="{ width: `${level * 100}%` }"
                />
            </div>
            <a
                :href="downloadUrl"
                class="ml-auto flex items-center gap-1 underline-offset-4 hover:text-primary hover:underline"
            >
                <Download class="h-3.5 w-3.5" /> WAV
            </a>
            <span v-if="loading">Loading…</span>
            <span v-if="error" class="text-destructive">{{ error }}</span>
        </div>
    </div>
</template>
