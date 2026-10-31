let context: AudioContext | null = null;

/**
 * The page's one AudioContext, created on first use. Browsers only let
 * audio start after the user interacts, so call this from a click.
 */
export async function audioContext(): Promise<AudioContext> {
    context ??= new AudioContext();

    if (context.state === 'suspended') {
        await context.resume();
    }

    return context;
}

/** Decoded files, kept so replaying does not fetch or decode them again. */
const buffers = new Map<string, Promise<AudioBuffer>>();

/** Fetch and decode an audio file. */
export async function loadAudio(src: string): Promise<AudioBuffer> {
    const context = await audioContext();

    if (!buffers.has(src)) {
        buffers.set(
            src,
            fetch(src, { credentials: 'same-origin' })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    return response.arrayBuffer();
                })
                .then((data) => context.decodeAudioData(data))
                .catch((error) => {
                    buffers.delete(src);
                    throw error;
                }),
        );
    }

    return buffers.get(src)!;
}

/** The loudest sample in each of a number of equal slices of the audio, from 0 to 1. */
export function peaks(buffer: AudioBuffer, count: number): Float32Array {
    const result = new Float32Array(count);
    const step = buffer.length / count;

    for (let channel = 0; channel < buffer.numberOfChannels; channel++) {
        const data = buffer.getChannelData(channel);

        for (let slice = 0; slice < count; slice++) {
            const end = Math.min(data.length, Math.floor((slice + 1) * step));

            for (let i = Math.floor(slice * step); i < end; i += 8) {
                const value = Math.abs(data[i]);

                if (value > result[slice]) {
                    result[slice] = value;
                }
            }
        }
    }

    return result;
}
