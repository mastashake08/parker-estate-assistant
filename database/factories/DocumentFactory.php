<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->paragraph()."\n\n".fake()->paragraph();

        return [
            'user_id' => User::factory(),
            'conversation_id' => (string) Str::uuid7(),
            'name' => fake()->word().'.txt',
            'mime_type' => 'text/plain',
            'size' => strlen($text),
            'path' => 'documents/'.Str::random(40).'.txt',
            'text' => $text,
        ];
    }
}
