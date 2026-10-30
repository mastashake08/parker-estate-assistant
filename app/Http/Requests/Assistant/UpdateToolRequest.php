<?php

namespace App\Http\Requests\Assistant;

use App\Services\ToolService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateToolRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(ToolService $tools): array
    {
        return [
            'tool' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($tools) {
                    if (! $tools->isKnown($this->user(), (string) $value)) {
                        $fail('The selected :attribute is invalid.');
                    }
                },
            ],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
