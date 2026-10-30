<?php

namespace App\Http\Requests\Assistant;

use App\Services\OllamaService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationMessageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'model' => array_filter([
                'nullable',
                'string',
                // Only ask Ollama for its models when one was chosen.
                $this->filled('model') ? Rule::in(app(OllamaService::class)->installedModels()) : null,
            ]),
        ];
    }
}
