/// <reference lib="webworker" />
import { BrowserMusicGen } from './musicgen';
import type { MusicGenLibrary, MusicGenRequest } from './musicgen';

/**
 * Runs MusicGen off the page's main thread, so the page stays responsive.
 * Transformers.js is only downloaded when this worker starts.
 */
let musicgen: Promise<BrowserMusicGen> | null = null;

const start = () =>
    (musicgen ??= import('@huggingface/transformers').then(
        (library) =>
            new BrowserMusicGen(
                library as unknown as MusicGenLibrary,
                'gpu' in navigator,
            ),
    ));

self.onmessage = async (
    event: MessageEvent<{ id: number; request: MusicGenRequest }>,
) => {
    const { id, request } = event.data;

    try {
        const result = await (
            await start()
        ).generate(request, (progress) =>
            self.postMessage({ id, type: 'progress', progress }),
        );

        self.postMessage({ id, type: 'done', result }, [result.wav]);
    } catch (error) {
        self.postMessage({
            id,
            type: 'error',
            message: error instanceof Error ? error.message : String(error),
        });
    }
};
