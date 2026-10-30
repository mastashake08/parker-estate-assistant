<?php

namespace App\Http\Requests\Assistant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreToolApprovalRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decisions' => ['required', 'array', 'max:20'],
            'decisions.*' => ['required', 'boolean'],
        ];
    }

    /**
     * Get the decisions as booleans, keyed by tool call ID.
     *
     * @return array<string, bool>
     */
    public function decisions(): array
    {
        return array_map(fn (mixed $approved) => filter_var($approved, FILTER_VALIDATE_BOOLEAN), $this->validated('decisions'));
    }
}
