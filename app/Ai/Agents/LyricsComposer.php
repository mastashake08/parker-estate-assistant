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
 * Writes lyrics and the vocal melody they are sung to, as structured
 * output: lines of notes, each with its lyric, pitch and length in beats.
 * DiffSinger sings the result. Llama 3 through Ollama by default.
 */
#[Temperature(0.9)]
class LyricsComposer implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  string|null  $language  The singing voice's language, such as "en", "zh" or "ja".
     */
    public function __construct(protected ?string $language = null) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<TEXT
        You are a songwriter. Write song lyrics and the vocal melody they are sung to. Reply only with the JSON the schema describes.

        - Write the song as lines. Each line is one sung phrase, followed by a rest of a beat or two for breath.
        - {$this->lyricRule()}
        - To hold a {$this->unit()} over several notes (a melisma), give the following notes the lyric "-".
        - "pitch" is a MIDI note number: 60 is middle C. Keep the melody singable, mostly between 55 and 74, with steps and small leaps.
        - "beats" is the note's length in quarter notes, such as 0.5, 1, 1.5 or 2. Give long notes to the ends of lines.
        - Stay in the key you choose, and match the rhythm of the words: stressed syllables on strong beats.
        TEXT;
    }

    /**
     * How lyrics are written for the voice's language, so its dictionary knows them.
     */
    protected function lyricRule(): string
    {
        return match (true) {
            str_starts_with((string) $this->language, 'zh') => 'Write the lyrics in Mandarin as toneless pinyin, one syllable per note, such as "ni", "hao", "lv" (for ü).',
            str_starts_with((string) $this->language, 'ja') => 'Write the lyrics in Japanese as romaji, one kana syllable per note, such as "sa", "ku", "ra".',
            default => 'Write the lyrics in English with one whole word per note: never split a word into syllables or add punctuation. Prefer common words.',
        };
    }

    /**
     * What one note's lyric is.
     */
    protected function unit(): string
    {
        return in_array(substr((string) $this->language, 0, 2), ['zh', 'ja'], true) ? 'syllable' : 'word';
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'key' => $schema->string()->required()->description('Such as "C major".'),
            'tempo' => $schema->integer()->min(50)->max(200)->required()->description('Beats per minute.'),
            'lines' => $schema->array()->min(1)->required()->items($schema->object([
                'notes' => $schema->array()->min(1)->required()->items($schema->object([
                    'lyric' => $schema->string()->required()->description('The word or syllable sung on this note, or "-" to hold the one before.'),
                    'pitch' => $schema->integer()->min(36)->max(96)->required(),
                    'beats' => $schema->number()->min(0.25)->max(8)->required(),
                ])),
                'rest' => $schema->number()->min(0)->max(8)->required()->description('Beats of rest after the line.'),
            ])),
        ];
    }

    /**
     * Get the provider-specific options to be passed to the provider.
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
