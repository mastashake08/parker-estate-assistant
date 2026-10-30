<?php

namespace App\Http\Requests\Assistant;

use App\Models\Skill;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSkillRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $skill = $this->route('skill');

        return [
            'name' => [
                'required', 'string', 'alpha_dash:ascii', 'max:64',
                Rule::unique('skills')
                    ->where('user_id', $this->user()->id)
                    ->ignore($skill instanceof Skill ? $skill->id : null),
            ],
            'description' => ['required', 'string', 'max:1000'],
            'instructions' => ['required', 'string', 'max:100000'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
