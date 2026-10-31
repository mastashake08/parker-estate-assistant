<?php

namespace App\Http\Requests\Audio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBrowserGenerationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:2000'],
            'duration' => ['required', 'integer', 'between:1,'.config('audio.music.browser_max_seconds')],
            'device' => ['nullable', 'string', 'in:webgpu,wasm'],
            'audio' => ['required', 'file', 'mimetypes:audio/wav,audio/x-wav,audio/wave,audio/vnd.wave', 'max:'.config('audio.music.upload_kilobytes')],
        ];
    }

    /**
     * The validated details of the music, as the audio service takes them.
     *
     * @return array{prompt: string, duration: int, device: string|null}
     */
    public function music(): array
    {
        $validated = $this->safe();

        return [
            'prompt' => $validated->string('prompt')->value(),
            'duration' => $validated->integer('duration'),
            'device' => $validated->filled('device') ? $validated->string('device')->value() : null,
        ];
    }
}
