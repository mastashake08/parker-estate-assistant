<?php

namespace App\Http\Requests\Audio;

use App\Enums\AudioKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAudioGenerationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $speech = $this->input('kind') === AudioKind::Speech->value;

        return [
            'kind' => ['required', Rule::enum(AudioKind::class)],
            'prompt' => ['required', 'string', 'max:'.($speech ? config('audio.speech.max_characters') : 2000)],

            // Speech
            'server' => ['required_if:kind,speech', 'nullable', 'string', Rule::in(config('audio.speech.servers'))],

            // Speech (a Phonon voice) and vocals (a DiffSinger voice and, for multi-speaker voices, the speaker)
            'voice' => ['required_if:kind,vocals', 'nullable', 'string', 'max:100'],
            'speaker' => ['nullable', 'string', 'max:100'],

            // Music and MIDI
            'model' => ['nullable', 'string', 'max:255', Rule::when(
                $this->input('kind') === AudioKind::Music->value,
                [Rule::in(config('audio.music.models'))],
            )],
            'duration' => ['required_if:kind,music', 'nullable', 'integer', 'between:1,'.config('audio.music.max_seconds')],

            // MIDI and vocals
            'bars' => ['required_if:kind,midi,vocals', 'nullable', 'integer', 'between:1,'.config('audio.midi.max_bars')],
            'tempo' => ['nullable', 'integer', 'between:40,220'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'server.required_if' => 'Choose a speech model.',
            'server.in' => 'Choose a speech model from the list.',
            'model.in' => 'Choose a MusicGen model from the list.',
            'voice.required_if' => 'Choose a singing voice.',
        ];
    }
}
