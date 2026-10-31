export type AudioKind = 'speech' | 'music' | 'midi' | 'vocals';

export type AudioGenerationStatus =
    | 'pending'
    | 'running'
    | 'completed'
    | 'failed';

export type MidiNote = {
    pitch: number;
    /** Beats from the start. */
    start: number;
    /** Length in beats. */
    duration: number;
    velocity: number;
};

export type MidiStem = {
    name: string;
    /** General MIDI program number. */
    program: number;
    /** 0-based MIDI channel; 9 is drums. */
    channel: number;
    drums: boolean;
    notes: MidiNote[];
};

export type AudioGeneration = {
    id: number;
    kind: AudioKind;
    status: AudioGenerationStatus;
    title: string;
    prompt: string;
    model: string | null;
    options: {
        server?: string;
        voice?: string | null;
        speaker?: string | null;
        duration?: number;
        bars?: number;
        tempo?: number | null;
        beats_per_bar?: number;
        key?: string;
        language?: string | null;
        lyrics?: string;
        unknown_words?: string[];
        lead_in_beats?: number;
        /** "browser" for music made in the browser and uploaded. */
        source?: 'browser';
        device?: 'webgpu' | 'wasm' | null;
    };
    size: number | null;
    duration: number | null;
    stems: MidiStem[] | null;
    error: string | null;
    has_score: boolean;
    created_at: string;
    finished_at: string | null;
};

export type SpeechServer = {
    url: string;
    online: boolean;
    model: string | null;
    languages: string[];
    voices: string[];
    error: string | null;
};

export type MusicGenStatus = {
    online: boolean;
    loaded: string | null;
    loading: string | null;
    device: string | null;
    models: string[];
    error: string | null;
};

export type SingingVoice = {
    name: string;
    language: string | null;
    languages: string[];
    speakers: string[];
    speaker: string | null;
    variance: boolean;
};

export type DiffSingerStatus = {
    online: boolean;
    voices: SingingVoice[];
    error: string | null;
};
