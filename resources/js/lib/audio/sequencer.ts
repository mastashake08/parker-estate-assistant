import type { MidiStem } from '@/types';

/**
 * Where the sequencer sends notes. Times are performance.now() milliseconds,
 * a little in the future, so the sink can schedule them precisely.
 */
export interface NoteSink {
    note(
        channel: number,
        pitch: number,
        velocity: number,
        at: number,
        length: number,
    ): void;
    program(channel: number, program: number, at: number): void;
    /** Silence everything now. */
    silence(): void;
}

type Scheduled = {
    stem: number;
    channel: number;
    pitch: number;
    velocity: number;
    start: number;
    duration: number;
};

export type PlayOptions = {
    stems: MidiStem[];
    tempo: number;
    /** The length of the piece in beats, so loops keep whole bars. */
    beats: number;
    loop: boolean;
    /** Whether a stem is silent right now; checked as notes are scheduled, so muting is live. */
    muted: (stem: number) => boolean;
    onEnd?: () => void;
};

const LOOKAHEAD_MS = 150;
const TICK_MS = 25;

/**
 * Plays MIDI stems through a sink with a lookahead scheduler: every few
 * milliseconds it sends the notes due in the next moment. Notes are never
 * queued far ahead, so stopping and muting take effect at once.
 */
export class Sequencer {
    private events: Scheduled[] = [];
    private next = 0;
    private startedAt = 0;
    private timer: ReturnType<typeof setInterval> | null = null;
    private options: PlayOptions | null = null;

    constructor(private sink: NoteSink) {}

    get playing(): boolean {
        return this.timer !== null;
    }

    /** The playhead, in beats from the start. */
    get position(): number {
        if (!this.options || !this.playing) {
            return 0;
        }

        const beats = (performance.now() - this.startedAt) / this.msPerBeat;

        return Math.max(0, beats);
    }

    private get msPerBeat(): number {
        return 60000 / (this.options?.tempo ?? 120);
    }

    play(options: PlayOptions): void {
        this.stop();
        this.options = options;
        this.events = options.stems
            .flatMap((stem, index) =>
                stem.notes.map((note) => ({
                    stem: index,
                    channel: stem.channel,
                    pitch: note.pitch,
                    velocity: note.velocity,
                    start: note.start,
                    duration: note.duration,
                })),
            )
            .sort((a, b) => a.start - b.start);

        // A short delay so the first notes are scheduled ahead, not late.
        this.startedAt = performance.now() + 50;
        this.next = 0;
        this.sendPrograms(this.startedAt);
        this.timer = setInterval(() => this.tick(), TICK_MS);
        this.tick();
    }

    stop(): void {
        if (this.timer !== null) {
            clearInterval(this.timer);
            this.timer = null;
        }

        this.sink.silence();
    }

    private sendPrograms(at: number): void {
        this.options?.stems.forEach((stem) => {
            if (!stem.drums) {
                this.sink.program(stem.channel, stem.program, at);
            }
        });
    }

    private tick(): void {
        const options = this.options;

        if (!options) {
            return;
        }

        const horizon = performance.now() + LOOKAHEAD_MS;
        const msPerBeat = this.msPerBeat;

        while (this.next < this.events.length) {
            const event = this.events[this.next];
            const at = this.startedAt + event.start * msPerBeat;

            if (at > horizon) {
                break;
            }

            if (!options.muted(event.stem)) {
                this.sink.note(
                    event.channel,
                    event.pitch,
                    event.velocity,
                    at,
                    event.duration * msPerBeat,
                );
            }

            this.next++;
        }

        const end = this.startedAt + options.beats * msPerBeat;

        if (this.next >= this.events.length && end <= horizon) {
            if (options.loop) {
                this.startedAt = end;
                this.next = 0;
                this.sendPrograms(end);
            } else if (performance.now() >= end) {
                this.stop();
                options.onEnd?.();
            }
        }
    }
}

/** Sends notes to a MIDI output, such as a synth or DAW, through the Web MIDI API. */
export class WebMidiSink implements NoteSink {
    constructor(private output: MIDIOutput) {}

    note(
        channel: number,
        pitch: number,
        velocity: number,
        at: number,
        length: number,
    ): void {
        this.output.send([0x90 | channel, pitch & 0x7f, velocity & 0x7f], at);
        this.output.send(
            [0x80 | channel, pitch & 0x7f, 0],
            at + Math.max(10, length - 5),
        );
    }

    program(channel: number, program: number, at: number): void {
        this.output.send([0xc0 | channel, program & 0x7f], at);
    }

    silence(): void {
        // Drop anything still queued, where the browser supports it, then release every note.
        (this.output as MIDIOutput & { clear?: () => void }).clear?.();

        for (let channel = 0; channel < 16; channel++) {
            this.output.send([0xb0 | channel, 123, 0]); // All notes off
            this.output.send([0xb0 | channel, 120, 0]); // All sound off
        }
    }
}

type Voice = {
    wave: OscillatorType;
    attack: number;
    decay: number;
    sustain: number;
    release: number;
    filter?: number;
    gain: number;
};

/** A rough instrument for each General MIDI family (eight programs each). */
const FAMILIES: Voice[] = [
    {
        wave: 'triangle',
        attack: 0.005,
        decay: 0.8,
        sustain: 0.15,
        release: 0.3,
        gain: 1,
    }, // Piano
    {
        wave: 'sine',
        attack: 0.002,
        decay: 0.6,
        sustain: 0,
        release: 0.4,
        gain: 1,
    }, // Chromatic percussion
    {
        wave: 'square',
        attack: 0.01,
        decay: 0.1,
        sustain: 0.8,
        release: 0.08,
        filter: 2500,
        gain: 0.45,
    }, // Organ
    {
        wave: 'triangle',
        attack: 0.003,
        decay: 0.5,
        sustain: 0.1,
        release: 0.2,
        filter: 3000,
        gain: 1,
    }, // Guitar
    {
        wave: 'sawtooth',
        attack: 0.005,
        decay: 0.3,
        sustain: 0.6,
        release: 0.1,
        filter: 700,
        gain: 0.9,
    }, // Bass
    {
        wave: 'sawtooth',
        attack: 0.12,
        decay: 0.2,
        sustain: 0.8,
        release: 0.3,
        filter: 1800,
        gain: 0.5,
    }, // Strings
    {
        wave: 'sawtooth',
        attack: 0.2,
        decay: 0.2,
        sustain: 0.85,
        release: 0.5,
        filter: 1500,
        gain: 0.45,
    }, // Ensemble and choir
    {
        wave: 'sawtooth',
        attack: 0.03,
        decay: 0.15,
        sustain: 0.75,
        release: 0.15,
        filter: 2200,
        gain: 0.5,
    }, // Brass
    {
        wave: 'square',
        attack: 0.03,
        decay: 0.1,
        sustain: 0.7,
        release: 0.12,
        filter: 2000,
        gain: 0.4,
    }, // Reed
    {
        wave: 'sine',
        attack: 0.04,
        decay: 0.1,
        sustain: 0.8,
        release: 0.15,
        gain: 0.9,
    }, // Pipe
    {
        wave: 'square',
        attack: 0.01,
        decay: 0.1,
        sustain: 0.7,
        release: 0.1,
        filter: 3500,
        gain: 0.4,
    }, // Lead
    {
        wave: 'triangle',
        attack: 0.4,
        decay: 0.3,
        sustain: 0.8,
        release: 0.8,
        gain: 0.8,
    }, // Pad
];

const DEFAULT_VOICE = FAMILIES[0];

/**
 * A small Web Audio synthesizer, so stems can be heard without a MIDI device:
 * oscillators shaped per instrument family, and synthesized drums on channel 10.
 */
export class SynthSink implements NoteSink {
    private programs = new Map<number, number>();
    private active = new Set<AudioScheduledSourceNode>();
    private output: GainNode;
    private noise: AudioBuffer;

    constructor(
        private context: AudioContext,
        volume = 0.8,
    ) {
        this.output = context.createGain();
        this.output.gain.value = volume;
        this.output.connect(context.destination);

        this.noise = context.createBuffer(
            1,
            context.sampleRate,
            context.sampleRate,
        );
        const samples = this.noise.getChannelData(0);
        for (let i = 0; i < samples.length; i++) {
            samples[i] = Math.random() * 2 - 1;
        }
    }

    set volume(value: number) {
        this.output.gain.value = value;
    }

    /** performance.now() milliseconds as AudioContext seconds. */
    private time(at: number): number {
        return Math.max(
            this.context.currentTime,
            this.context.currentTime + (at - performance.now()) / 1000,
        );
    }

    program(channel: number, program: number): void {
        this.programs.set(channel, program);
    }

    note(
        channel: number,
        pitch: number,
        velocity: number,
        at: number,
        length: number,
    ): void {
        const start = this.time(at);
        const level = Math.pow(velocity / 127, 1.5) * 0.3;

        if (channel === 9) {
            this.drum(pitch, start, level);

            return;
        }

        const voice =
            FAMILIES[Math.floor((this.programs.get(channel) ?? 0) / 8)] ??
            DEFAULT_VOICE;
        // Release after the attack, for very short notes too.
        const end = start + Math.max(voice.attack + 0.01, length / 1000);
        const peak = level * voice.gain;

        const oscillator = this.context.createOscillator();
        oscillator.type = voice.wave;
        oscillator.frequency.value = 440 * Math.pow(2, (pitch - 69) / 12);

        const envelope = this.context.createGain();
        envelope.gain.setValueAtTime(0, start);
        envelope.gain.linearRampToValueAtTime(peak, start + voice.attack);
        envelope.gain.setTargetAtTime(
            peak * voice.sustain,
            start + voice.attack,
            voice.decay / 3,
        );
        // setTargetAtTime starts from wherever the decay has reached by then.
        envelope.gain.setTargetAtTime(0, end, voice.release / 3);

        let node: AudioNode = oscillator;

        if (voice.filter) {
            const filter = this.context.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.value = voice.filter;
            oscillator.connect(filter);
            node = filter;
        }

        node.connect(envelope).connect(this.output);
        this.start(oscillator, start, end + voice.release * 2);
    }

    private drum(pitch: number, start: number, level: number): void {
        if (pitch === 35 || pitch === 36) {
            this.tone(start, 150, 45, 0.35, level * 2.2);
        } else if ([41, 43, 45, 47, 48, 50].includes(pitch)) {
            this.tone(
                start,
                80 + (pitch - 41) * 18,
                50 + (pitch - 41) * 8,
                0.3,
                level * 1.6,
            );
        } else if ([37, 38, 39, 40].includes(pitch)) {
            this.hiss(
                start,
                'bandpass',
                1800,
                pitch === 39 ? 0.12 : 0.18,
                level * 1.6,
            );
            this.tone(start, 220, 160, 0.08, level);
        } else if ([42, 44].includes(pitch)) {
            this.hiss(start, 'highpass', 7000, 0.05, level * 0.8);
        } else if (pitch === 46) {
            this.hiss(start, 'highpass', 7000, 0.3, level * 0.8);
        } else {
            // Cymbals and everything else.
            this.hiss(start, 'highpass', 5000, 0.9, level * 0.7);
        }
    }

    /** A pitched hit that falls from one frequency to another. */
    private tone(
        start: number,
        from: number,
        to: number,
        length: number,
        level: number,
    ): void {
        const oscillator = this.context.createOscillator();
        oscillator.frequency.setValueAtTime(from, start);
        oscillator.frequency.exponentialRampToValueAtTime(to, start + length);

        const envelope = this.context.createGain();
        envelope.gain.setValueAtTime(level, start);
        envelope.gain.exponentialRampToValueAtTime(0.001, start + length);

        oscillator.connect(envelope).connect(this.output);
        this.start(oscillator, start, start + length);
    }

    /** Filtered noise, for snares, hats and cymbals. */
    private hiss(
        start: number,
        type: BiquadFilterType,
        frequency: number,
        length: number,
        level: number,
    ): void {
        const source = this.context.createBufferSource();
        source.buffer = this.noise;

        const filter = this.context.createBiquadFilter();
        filter.type = type;
        filter.frequency.value = frequency;

        const envelope = this.context.createGain();
        envelope.gain.setValueAtTime(level, start);
        envelope.gain.exponentialRampToValueAtTime(0.001, start + length);

        source.connect(filter).connect(envelope).connect(this.output);
        this.start(source, start, start + length);
    }

    private start(
        node: AudioScheduledSourceNode,
        start: number,
        stop: number,
    ): void {
        this.active.add(node);
        node.onended = () => this.active.delete(node);
        node.start(start);
        node.stop(stop);
    }

    silence(): void {
        const now = this.context.currentTime;

        this.active.forEach((node) => {
            try {
                node.stop(now);
            } catch {
                // Already stopped.
            }
        });
        this.active.clear();
    }

    disconnect(): void {
        this.silence();
        this.output.disconnect();
    }
}
