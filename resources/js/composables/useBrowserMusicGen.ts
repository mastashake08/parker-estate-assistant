import { reactive } from 'vue';
import type {
    MusicGenProgress,
    MusicGenRequest,
    MusicGenResult,
} from '@/lib/audio/musicgen';

type Reply =
    | { id: number; type: 'progress'; progress: MusicGenProgress }
    | { id: number; type: 'done'; result: MusicGenResult }
    | { id: number; type: 'error'; message: string };

const state = reactive({
    supported:
        typeof Worker !== 'undefined' && typeof WebAssembly !== 'undefined',
    webgpu: typeof navigator !== 'undefined' && 'gpu' in navigator,
    busy: false,
    progress: null as MusicGenProgress | null,
});

let worker: Worker | null = null;
let nextId = 0;
const pending = new Map<
    number,
    {
        resolve: (result: MusicGenResult) => void;
        reject: (error: Error) => void;
    }
>();

const connect = () => {
    if (worker) {
        return worker;
    }

    worker = new Worker(
        new URL('../lib/audio/musicgen.worker.ts', import.meta.url),
        { type: 'module' },
    );
    worker.onmessage = ({ data }: MessageEvent<Reply>) => {
        const request = pending.get(data.id);

        if (data.type === 'progress') {
            state.progress = data.progress;

            return;
        }

        pending.delete(data.id);
        state.busy = pending.size > 0;
        state.progress = null;

        if (data.type === 'done') {
            request?.resolve(data.result);
        } else {
            request?.reject(new Error(data.message));
        }
    };
    worker.onerror = (event) => {
        pending.forEach(({ reject }) =>
            reject(
                new Error(event.message || 'MusicGen stopped unexpectedly.'),
            ),
        );
        pending.clear();
        state.busy = false;
        state.progress = null;
        worker?.terminate();
        worker = null;
    };

    return worker;
};

/**
 * Makes music in this browser with MusicGen and Transformers.js, in a Web
 * Worker shared by the whole page, so the model stays loaded between generations.
 */
export function useBrowserMusicGen() {
    const generate = (request: MusicGenRequest) =>
        new Promise<MusicGenResult>((resolve, reject) => {
            const id = ++nextId;

            pending.set(id, { resolve, reject });
            state.busy = true;
            state.progress = {
                stage: 'loading',
                progress: 0,
                loaded: 0,
                total: 0,
            };
            connect().postMessage({ id, request });
        });

    return { browser: state, generate };
}
