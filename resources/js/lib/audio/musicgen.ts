import { encodeWav } from './wav';

/** MusicGen's audio codec makes 50 frames (tokens) per second of audio. */
export const TOKENS_PER_SECOND = 50;

export type BrowserDevice = 'webgpu' | 'wasm';

export type MusicGenProgress =
    | { stage: 'loading'; progress: number; loaded: number; total: number }
    | { stage: 'generating'; progress: number };

export type MusicGenRequest = {
    model: string;
    prompt: string;
    seconds: number;
    guidanceScale?: number;
};

export type MusicGenResult = {
    wav: ArrayBuffer;
    sampleRate: number;
    seconds: number;
    device: BrowserDevice;
};

/** The parts of Transformers.js this uses, so a stand-in can be given in tests. */
export type MusicGenLibrary = {
    AutoTokenizer: {
        from_pretrained(
            model: string,
            options?: Record<string, unknown>,
        ): Promise<(text: string) => Record<string, unknown>>;
    };
    MusicgenForConditionalGeneration: {
        from_pretrained(
            model: string,
            options: Record<string, unknown>,
        ): Promise<MusicGenModel>;
    };
};

type MusicGenModel = {
    config: { audio_encoder: { sampling_rate: number } };
    generate(
        options: Record<string, unknown>,
    ): Promise<{ data: Float32Array; dims: number[] }>;
};

type Loaded = {
    model: string;
    device: BrowserDevice;
    tokenizer: (text: string) => Record<string, unknown>;
    musicgen: MusicGenModel;
};

/** Smaller weights for the text encoder and decoder; the audio decoder needs full precision. */
const DTYPES = {
    text_encoder: 'q8',
    decoder_model_merged: 'q8',
    encodec_decode: 'fp32',
};

/**
 * Runs MusicGen with Transformers.js. The model stays loaded between
 * generations, and the browser caches its files, so only the first
 * generation downloads it.
 */
export class BrowserMusicGen {
    private loaded: Loaded | null = null;

    constructor(
        private library: MusicGenLibrary,
        private webgpu: boolean,
    ) {}

    private async load(
        model: string,
        onProgress: (progress: MusicGenProgress) => void,
    ): Promise<Loaded> {
        if (this.loaded?.model === model) {
            return this.loaded;
        }

        this.loaded = null;

        const files = new Map<string, { loaded: number; total: number }>();
        const progress_callback = (info: {
            status: string;
            file?: string;
            loaded?: number;
            total?: number;
        }) => {
            if (info.status !== 'progress' || !info.file) {
                return;
            }

            files.set(info.file, {
                loaded: info.loaded ?? 0,
                total: info.total ?? 0,
            });
            const loaded = [...files.values()].reduce(
                (sum, file) => sum + file.loaded,
                0,
            );
            const total = [...files.values()].reduce(
                (sum, file) => sum + file.total,
                0,
            );
            onProgress({
                stage: 'loading',
                progress: total ? loaded / total : 0,
                loaded,
                total,
            });
        };

        const tokenizer = await this.library.AutoTokenizer.from_pretrained(
            model,
            { progress_callback },
        );

        // WebGPU is much faster where it works; fall back to WebAssembly where it does not.
        const devices: BrowserDevice[] = this.webgpu
            ? ['webgpu', 'wasm']
            : ['wasm'];
        let lastError: unknown = null;

        for (const device of devices) {
            try {
                const musicgen =
                    await this.library.MusicgenForConditionalGeneration.from_pretrained(
                        model,
                        {
                            device,
                            dtype: DTYPES,
                            progress_callback,
                        },
                    );

                return (this.loaded = { model, device, tokenizer, musicgen });
            } catch (error) {
                lastError = error;
            }
        }

        throw lastError instanceof Error
            ? lastError
            : new Error('MusicGen could not be loaded in this browser.');
    }

    async generate(
        request: MusicGenRequest,
        onProgress: (progress: MusicGenProgress) => void,
    ): Promise<MusicGenResult> {
        const { device, tokenizer, musicgen } = await this.load(
            request.model,
            onProgress,
        );
        const tokens = Math.round(request.seconds * TOKENS_PER_SECOND);
        let steps = 0;

        onProgress({ stage: 'generating', progress: 0 });

        const audio = await musicgen.generate({
            ...tokenizer(request.prompt),
            max_new_tokens: tokens,
            do_sample: true,
            guidance_scale: request.guidanceScale ?? 3,
            // Called once per generated step, which is enough to show progress.
            streamer: {
                put: () =>
                    onProgress({
                        stage: 'generating',
                        progress: Math.min(0.99, ++steps / tokens),
                    }),
                end: () => undefined,
            },
        });

        // The audio is shaped [batch, channels, samples].
        const [, channelCount = 1, length = audio.data.length] = audio.dims;
        const channels = Array.from({ length: channelCount }, (_, channel) =>
            audio.data.subarray(channel * length, (channel + 1) * length),
        );
        const sampleRate = musicgen.config.audio_encoder.sampling_rate;

        return {
            wav: encodeWav(channels, sampleRate),
            sampleRate,
            seconds: length / sampleRate,
            device,
        };
    }
}
