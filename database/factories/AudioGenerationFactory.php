<?php

namespace Database\Factories;

use App\Enums\AudioGenerationStatus;
use App\Enums\AudioKind;
use App\Models\AudioGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AudioGeneration>
 */
class AudioGenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => AudioKind::Music,
            'status' => AudioGenerationStatus::Pending,
            'title' => 'Lo-fi beat with mellow piano',
            'prompt' => 'Lo-fi beat with mellow piano',
            'model' => 'facebook/musicgen-small',
            'options' => ['duration' => 10],
        ];
    }

    /**
     * Speech from a Phonon server.
     */
    public function speech(): static
    {
        return $this->state(fn () => [
            'kind' => AudioKind::Speech,
            'title' => 'Welcome to the estate.',
            'prompt' => 'Welcome to the estate.',
            'model' => null,
            'options' => ['server' => 'http://phonon:8880', 'voice' => null],
        ]);
    }

    /**
     * MIDI written by the language model.
     */
    public function midi(): static
    {
        return $this->state(fn () => [
            'kind' => AudioKind::Midi,
            'title' => 'A jazzy trio groove',
            'prompt' => 'A jazzy trio groove',
            'model' => 'llama3',
            'options' => ['bars' => 4, 'tempo' => null],
        ]);
    }

    /**
     * A finished generation with its file stored.
     */
    public function completed(string $path = 'audio/1/example.wav'): static
    {
        return $this->state(fn () => [
            'status' => AudioGenerationStatus::Completed,
            'path' => $path,
            'size' => 44,
            'duration' => 1.0,
            'finished_at' => now(),
        ]);
    }
}
