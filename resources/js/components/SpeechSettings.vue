<script setup lang="ts">
import { Volume2 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useSpeech } from '@/composables/useSpeech';

const { isSupported, settings, voices, speakingId, toggle } = useSpeech();

/** Voices in the page's language first, then the rest, each sorted by name. */
const sortedVoices = computed(() => {
    const language = (document.documentElement.lang || 'en').slice(0, 2);
    const matches = (voice: SpeechSynthesisVoice) =>
        voice.lang.toLowerCase().startsWith(language);

    return [...voices.value].sort(
        (a, b) =>
            Number(matches(b)) - Number(matches(a)) ||
            a.name.localeCompare(b.name),
    );
});

const sample =
    'Hello! This is how your assistant will sound when it reads replies aloud.';
</script>

<template>
    <div class="space-y-4">
        <p v-if="!isSupported" class="text-sm text-muted-foreground">
            This browser can't read text aloud.
        </p>

        <template v-else>
            <Label class="flex items-center gap-2">
                <Checkbox
                    :model-value="settings.autoRead"
                    @update:model-value="settings.autoRead = $event === true"
                />
                Read new replies aloud automatically
            </Label>

            <div class="grid gap-2">
                <Label for="speech-voice">Voice</Label>
                <select
                    id="speech-voice"
                    v-model="settings.voice"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-input/30"
                >
                    <option :value="null">Browser default</option>
                    <option
                        v-for="voice in sortedVoices"
                        :key="voice.voiceURI"
                        :value="voice.voiceURI"
                    >
                        {{ voice.name }} ({{ voice.lang }})
                    </option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="speech-rate"
                    >Speed: {{ settings.rate.toFixed(1) }}×</Label
                >
                <input
                    id="speech-rate"
                    v-model.number="settings.rate"
                    type="range"
                    min="0.5"
                    max="2"
                    step="0.1"
                    class="w-full accent-primary"
                />
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="toggle('sample', sample)"
            >
                <Volume2 class="h-4 w-4" />
                {{ speakingId === 'sample' ? 'Stop' : 'Try this voice' }}
            </Button>

            <p class="text-xs text-muted-foreground">
                Voices come from this device, so these settings are saved in
                this browser only.
            </p>
        </template>
    </div>
</template>
