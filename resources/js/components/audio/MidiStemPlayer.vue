<script setup lang="ts">
import { Download, Piano, Play, Repeat, Square } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import { useWebMidi } from '@/composables/useWebMidi';
import { audioContext } from '@/lib/audio/context';
import { instrumentName } from '@/lib/audio/gm';
import { Sequencer, SynthSink, WebMidiSink } from '@/lib/audio/sequencer';
import type { MidiStem } from '@/types';

const props = defineProps<{
    stems: MidiStem[];
    tempo: number;
    beatsPerBar: number;
    /** The URL of one stem's MIDI file, or of the whole file without a stem. */
    downloadUrl: (stem?: number) => string;
}>();

const { midi, enable, select, output } = useWebMidi();

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-xs dark:bg-input/30';

const tempo = ref(props.tempo);
const loop = ref(false);
const playing = ref(false);
const position = ref(0);
const muted = reactive<boolean[]>(props.stems.map(() => false));
const solo = ref<number | null>(null);

/** The piece's length in whole bars. */
const beats = computed(() => {
    const last = Math.max(
        0,
        ...props.stems.flatMap((stem) =>
            stem.notes.map((note) => note.start + note.duration),
        ),
    );

    return Math.max(
        props.beatsPerBar,
        Math.ceil(last / props.beatsPerBar) * props.beatsPerBar,
    );
});

const silent = (stem: number) =>
    solo.value !== null ? solo.value !== stem : muted[stem];

let synth: SynthSink | null = null;
let sequencer: Sequencer | null = null;
let frame = 0;

const tick = () => {
    position.value = sequencer?.playing ? sequencer.position % beats.value : 0;
    frame = requestAnimationFrame(tick);
};

const stop = () => {
    sequencer?.stop();
    sequencer = null;
    playing.value = false;
};

const play = async () => {
    stop();

    const device = output();
    let sink;

    if (device) {
        sink = new WebMidiSink(device);
    } else {
        synth ??= new SynthSink(await audioContext());
        sink = synth;
    }

    sequencer = new Sequencer(sink);
    sequencer.play({
        stems: props.stems,
        tempo: tempo.value,
        beats: beats.value,
        loop: loop.value,
        muted: silent,
        onEnd: () => {
            playing.value = false;
        },
    });
    playing.value = true;
};

// The tempo, loop and output are read when playback starts, so restart to apply them.
watch([tempo, loop, () => midi.selected], () => {
    if (playing.value) {
        play();
    }
});

const toggleSolo = (stem: number) => {
    solo.value = solo.value === stem ? null : stem;
};

const rolls = ref<HTMLCanvasElement[]>([]);

/** Draw each stem's notes as a piano roll. */
const drawRolls = () => {
    rolls.value.forEach((canvas, index) => {
        const stem = props.stems[index];
        const context = canvas?.getContext('2d');

        if (!stem || !context) {
            return;
        }

        const ratio = window.devicePixelRatio || 1;
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        context.scale(ratio, ratio);
        context.clearRect(0, 0, width, height);

        const style = getComputedStyle(canvas);
        const pitches = stem.notes.map((note) => note.pitch);
        const low = Math.min(...pitches) - 1;
        const high = Math.max(...pitches) + 1;
        const row = height / Math.max(1, high - low);

        // Bar lines.
        context.fillStyle =
            style.getPropertyValue('--grid').trim() || 'rgba(128,128,128,0.2)';
        for (let bar = 0; bar <= beats.value; bar += props.beatsPerBar) {
            context.fillRect((bar / beats.value) * width, 0, 1, height);
        }

        context.fillStyle =
            style.getPropertyValue('--note').trim() || style.color;
        stem.notes.forEach((note) => {
            context.globalAlpha = 0.45 + (note.velocity / 127) * 0.55;
            context.fillRect(
                (note.start / beats.value) * width,
                height - (note.pitch - low + 1) * row,
                Math.max(1.5, (note.duration / beats.value) * width - 1),
                Math.max(1.5, row - 0.5),
            );
        });
        context.globalAlpha = 1;
    });
};

let resize: ResizeObserver | null = null;

onMounted(async () => {
    frame = requestAnimationFrame(tick);
    await nextTick();
    drawRolls();
    resize = new ResizeObserver(drawRolls);
    rolls.value.forEach((canvas) => canvas && resize?.observe(canvas));
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    resize?.disconnect();
    stop();
    synth?.disconnect();
});
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <Button
                size="sm"
                :variant="playing ? 'secondary' : 'default'"
                @click="playing ? stop() : play()"
            >
                <Square v-if="playing" class="h-3.5 w-3.5" />
                <Play v-else class="h-3.5 w-3.5" />
                {{ playing ? 'Stop' : 'Play' }}
            </Button>
            <Button
                size="sm"
                :variant="loop ? 'secondary' : 'ghost'"
                :aria-pressed="loop"
                @click="loop = !loop"
            >
                <Repeat class="h-3.5 w-3.5" /> Loop
            </Button>
            <label class="flex items-center gap-1 text-muted-foreground">
                Tempo
                <input
                    v-model.number="tempo"
                    type="number"
                    min="40"
                    max="220"
                    class="h-8 w-16 rounded-md border border-input bg-transparent px-2 dark:bg-input/30"
                />
                BPM
            </label>

            <div class="ml-auto flex items-center gap-2">
                <Piano class="h-4 w-4 text-muted-foreground" />
                <template v-if="midi.enabled">
                    <select
                        :value="midi.selected"
                        :class="selectClass"
                        aria-label="Play through"
                        @change="
                            select(($event.target as HTMLSelectElement).value)
                        "
                    >
                        <option value="">Built-in synth</option>
                        <option
                            v-for="device in midi.outputs"
                            :key="device.id"
                            :value="device.id"
                        >
                            {{ device.name }}
                        </option>
                    </select>
                </template>
                <template v-else>
                    <span class="text-muted-foreground">Built-in synth</span>
                    <Button
                        v-if="midi.supported"
                        size="sm"
                        variant="outline"
                        :disabled="midi.requesting"
                        @click="enable"
                        >Use MIDI devices</Button
                    >
                    <span v-else class="text-muted-foreground"
                        >(this browser has no Web MIDI)</span
                    >
                </template>
            </div>
        </div>
        <p v-if="midi.error" class="text-xs text-destructive">
            {{ midi.error }}
        </p>
        <p
            v-else-if="midi.enabled && !midi.outputs.length"
            class="text-xs text-muted-foreground"
        >
            No MIDI outputs are connected. Plug in a synth or start a virtual
            MIDI port, and it will appear here.
        </p>

        <ul class="space-y-2">
            <li v-for="(stem, index) in stems" :key="index" class="space-y-1">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-medium">{{ stem.name }}</span>
                    <span class="text-muted-foreground"
                        >{{ instrumentName(stem.program, stem.drums) }} · ch
                        {{ stem.channel + 1 }} ·
                        {{ stem.notes.length }} notes</span
                    >
                    <div class="ml-auto flex items-center gap-1">
                        <Button
                            size="sm"
                            class="h-6 px-2"
                            :variant="muted[index] ? 'secondary' : 'ghost'"
                            :aria-pressed="muted[index]"
                            @click="muted[index] = !muted[index]"
                            >M</Button
                        >
                        <Button
                            size="sm"
                            class="h-6 px-2"
                            :variant="solo === index ? 'secondary' : 'ghost'"
                            :aria-pressed="solo === index"
                            @click="toggleSolo(index)"
                            >S</Button
                        >
                        <a
                            :href="downloadUrl(index)"
                            class="flex h-6 items-center px-1 text-muted-foreground hover:text-primary"
                            :aria-label="`Download ${stem.name} as MIDI`"
                            ><Download class="h-3.5 w-3.5"
                        /></a>
                    </div>
                </div>
                <div
                    class="relative h-12 overflow-hidden rounded border bg-muted/30 text-primary [--grid:color-mix(in_oklab,currentColor_15%,transparent)] [--note:var(--color-primary)]"
                    :class="{ 'opacity-40': silent(index) }"
                >
                    <canvas
                        :ref="
                            (element) =>
                                (rolls[index] = element as HTMLCanvasElement)
                        "
                        class="h-full w-full"
                    />
                    <div
                        v-if="playing"
                        class="pointer-events-none absolute inset-y-0 w-px bg-foreground"
                        :style="{ left: `${(position / beats) * 100}%` }"
                    />
                </div>
            </li>
        </ul>

        <a
            :href="downloadUrl()"
            class="inline-flex items-center gap-1 text-xs text-muted-foreground underline-offset-4 hover:text-primary hover:underline"
            ><Download class="h-3.5 w-3.5" /> All stems (.mid)</a
        >
    </div>
</template>
