import { reactive } from 'vue';

export type MidiOutputInfo = {
    id: string;
    name: string;
    manufacturer: string;
};

const STORAGE_KEY = 'audio.midiOutput';

const read = () => {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
};

const state = reactive({
    supported:
        typeof navigator !== 'undefined' && 'requestMIDIAccess' in navigator,
    enabled: false,
    requesting: false,
    error: null as string | null,
    outputs: [] as MidiOutputInfo[],
    /** The chosen output's ID, or "" for the built-in synth. */
    selected: read() ?? '',
});

let access: MIDIAccess | null = null;

const refresh = () => {
    state.outputs = access
        ? Array.from(access.outputs.values())
              .filter((output) => output.state === 'connected')
              .map((output) => ({
                  id: output.id,
                  name: output.name ?? 'MIDI output',
                  manufacturer: output.manufacturer ?? '',
              }))
        : [];
};

/**
 * The browser's MIDI outputs through the Web MIDI API, shared by every
 * player on the page. Access is asked for once, when the user enables it.
 */
export function useWebMidi() {
    const enable = async () => {
        if (!state.supported || access || state.requesting) {
            return;
        }

        state.requesting = true;
        state.error = null;

        try {
            access = await navigator.requestMIDIAccess({ sysex: false });
            access.onstatechange = refresh;
            state.enabled = true;
            refresh();
        } catch (error) {
            state.error =
                error instanceof Error && error.name === 'SecurityError'
                    ? 'MIDI access was blocked. Allow it in the site settings to use MIDI devices.'
                    : 'MIDI devices could not be opened.';
        } finally {
            state.requesting = false;
        }
    };

    const select = (id: string) => {
        state.selected = id;

        try {
            localStorage.setItem(STORAGE_KEY, id);
        } catch {
            // Not remembered on this device.
        }
    };

    /** The chosen output, if it is still connected. */
    const output = (): MIDIOutput | null =>
        (state.selected && access?.outputs.get(state.selected)) || null;

    return { midi: state, enable, select, output };
}
