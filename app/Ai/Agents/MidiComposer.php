<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Composes multi-track MIDI as structured output: each stem's instrument
 * and notes, timed in beats. Llama 3 through Ollama by default.
 */
#[Temperature(0.8)]
class MidiComposer implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You are a composer who writes music as MIDI data. Reply only with the JSON the schema describes.

        - Write one track per stem, such as drums, bass, chords and melody.
        - Times are in beats (quarter notes) from the start of the piece: a note at "start" 4 with "duration" 0.5 is an eighth note on the first beat of bar 2 in 4/4.
        - "pitch" is a MIDI note number: 60 is middle C, 48 the C below. Keep bass between 28 and 52, chords between 48 and 72, and melodies between 60 and 84.
        - "instrument" is a General MIDI program number from 0 to 127: 0 piano, 4 electric piano, 24 nylon guitar, 33 electric bass, 38 synth bass, 48 strings, 56 trumpet, 65 alto sax, 73 flute, 80 square lead, 81 saw lead, 88 new age pad, 89 warm pad.
        - For a drum track, set "drums" to true and use General MIDI drum notes: 36 kick, 38 snare, 39 clap, 42 closed hi-hat, 46 open hi-hat, 49 crash, 51 ride, 45 low tom, 48 high tom.
        - "velocity" is from 1 to 127: about 100 for accents and 60 to 80 otherwise.
        - Stay in the key you choose, fill every bar you are asked for, and keep each part musical: repeat and vary motifs, and give the bass and chords a clear progression.
        TEXT;
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $note = $schema->object([
            'pitch' => $schema->integer()->min(0)->max(127)->required(),
            'start' => $schema->number()->min(0)->required()->description('Beats from the start of the piece.'),
            'duration' => $schema->number()->min(0.0625)->required()->description('Length in beats.'),
            'velocity' => $schema->integer()->min(1)->max(127)->required(),
        ]);

        return [
            'title' => $schema->string()->required(),
            'key' => $schema->string()->required()->description('Such as "A minor".'),
            'tempo' => $schema->integer()->min(40)->max(220)->required()->description('Beats per minute.'),
            'tracks' => $schema->array()->min(1)->max((int) config('audio.midi.max_tracks'))->required()->items($schema->object([
                'name' => $schema->string()->required(),
                'instrument' => $schema->integer()->min(0)->max(127)->required()->description('General MIDI program number.'),
                'drums' => $schema->boolean()->required(),
                'notes' => $schema->array()->required()->items($note),
            ])),
        ];
    }

    /**
     * Get the provider-specific options to be passed to the provider.
     *
     * Note lists are long, so Ollama is given room for them.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        return $name === Lab::Ollama->value
            ? ['num_ctx' => max(8192, config()->integer('assistant.context_tokens')), 'num_predict' => -1]
            : [];
    }
}
