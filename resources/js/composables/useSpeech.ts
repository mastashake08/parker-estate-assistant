import { readonly, ref, watch } from 'vue';

export type SpeechSettings = {
    /** Read each new reply aloud as soon as it arrives. */
    autoRead: boolean;
    /** The chosen voice's URI, or null for the browser's default voice. */
    voice: string | null;
    /** Speaking speed, where 1 is normal. */
    rate: number;
};

const STORAGE_KEY = 'speech';

/** Chrome stops long utterances partway through, so text is spoken in chunks of about this many characters. */
const CHUNK_CHARACTERS = 200;

const isSupported =
    typeof window !== 'undefined' && 'speechSynthesis' in window;

const defaults: SpeechSettings = { autoRead: false, voice: null, rate: 1 };

const loadSettings = (): SpeechSettings => {
    try {
        return {
            ...defaults,
            ...JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}'),
        };
    } catch {
        return { ...defaults };
    }
};

// Shared by every component, so the chat and the settings page agree.
const settings = ref<SpeechSettings>(
    typeof window === 'undefined' ? { ...defaults } : loadSettings(),
);
const voices = ref<SpeechSynthesisVoice[]>([]);
const speakingId = ref<string | null>(null);

/** Increases on every new utterance, so callbacks from cancelled speech are ignored. */
let generation = 0;

watch(
    settings,
    (value) => {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
        } catch {
            // Private browsing can block storage; the settings still apply until reload.
        }
    },
    { deep: true },
);

if (isSupported) {
    const loadVoices = () => (voices.value = speechSynthesis.getVoices());

    loadVoices();
    speechSynthesis.addEventListener('voiceschanged', loadVoices);
}

/**
 * The text a rendered reply would be read as, with a pause after each
 * paragraph, heading and list item.
 *
 * DOMParser builds an inert document, so nothing in the HTML runs or loads.
 */
export const textFromHtml = (html: string): string => {
    const document = new DOMParser().parseFromString(html, 'text/html');

    document
        .querySelectorAll('p, li, h1, h2, h3, h4, h5, h6, pre, tr, blockquote')
        .forEach((element) => element.append('\n'));

    return (document.body.textContent ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => (/[.!?:;]$/.test(line) ? line : `${line}.`))
        .join('\n');
};

/** Split text into sentence-sized chunks of up to about CHUNK_CHARACTERS. */
const chunks = (text: string): string[] => {
    // Long sentences are broken at spaces, or anywhere if a word is too long.
    const tooLong = new RegExp(
        `.{1,${CHUNK_CHARACTERS}}(?:\\s+|$)|.{1,${CHUNK_CHARACTERS}}`,
        'g',
    );
    const sentences = (text.match(/[^.!?\n]+[.!?]*\s*/g) ?? [text]).flatMap(
        (sentence) =>
            sentence.length <= CHUNK_CHARACTERS
                ? [sentence]
                : (sentence.match(tooLong) ?? [sentence]),
    );

    return sentences.reduce<string[]>((result, sentence) => {
        const last = result.at(-1);

        if (
            last !== undefined &&
            (last + sentence).length <= CHUNK_CHARACTERS
        ) {
            result[result.length - 1] = last + sentence;
        } else {
            result.push(sentence);
        }

        return result;
    }, []);
};

const stop = () => {
    generation++;
    speakingId.value = null;

    if (isSupported) {
        speechSynthesis.cancel();
    }
};

/** Read text aloud, stopping anything already being read. */
const speak = (id: string, text: string) => {
    if (!isSupported) {
        return;
    }

    stop();

    const parts = chunks(text.trim()).filter((part) => part.trim());

    if (!parts.length) {
        return;
    }

    const current = generation;
    const voice =
        voices.value.find(
            (option) => option.voiceURI === settings.value.voice,
        ) ?? null;
    const finish = () => {
        if (current === generation) {
            speakingId.value = null;
        }
    };

    speakingId.value = id;

    parts.forEach((part, index) => {
        const utterance = new SpeechSynthesisUtterance(part);

        utterance.voice = voice;
        utterance.lang = voice?.lang ?? document.documentElement.lang;
        utterance.rate = settings.value.rate;
        utterance.onerror = finish;

        if (index === parts.length - 1) {
            utterance.onend = finish;
        }

        speechSynthesis.speak(utterance);
    });
};

/** Read text aloud, or stop if it is the text being read. */
const toggle = (id: string, text: string) =>
    speakingId.value === id ? stop() : speak(id, text);

/**
 * Some browsers (Safari on iPhone) only allow speech that starts from a tap.
 * Speaking nothing during one lets a reply that arrives later be read aloud.
 */
const unlock = () => {
    if (isSupported && settings.value.autoRead) {
        speechSynthesis.speak(new SpeechSynthesisUtterance(''));
    }
};

export function useSpeech() {
    return {
        isSupported,
        settings,
        voices: readonly(voices),
        speakingId: readonly(speakingId),
        speak,
        stop,
        toggle,
        unlock,
    };
}
