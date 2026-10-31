<?php

namespace App\Http\Requests\Assistant;

use App\Enums\RoutineFrequency;
use App\Models\Routine;
use App\Services\ToolService;
use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoutineRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $routine = $this->route('routine');

        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('routines')
                    ->where('user_id', $this->user()->id)
                    ->ignore($routine instanceof Routine ? $routine->id : null),
            ],
            'instructions' => ['required', 'string', 'max:10000'],
            'frequency' => ['required', Rule::enum(RoutineFrequency::class)],
            'time' => ['required_if:frequency,daily,weekdays,weekly', 'nullable', 'date_format:H:i'],
            'day_of_week' => ['required_if:frequency,weekly', 'nullable', 'integer', 'between:0,6'],
            'every_hours' => ['required_if:frequency,hourly', 'nullable', 'integer', 'between:1,23'],
            'cron' => [
                'required_if:frequency,custom', 'nullable', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($this->input('frequency') === RoutineFrequency::Custom->value && ! CronExpression::isValidExpression((string) $value)) {
                        $fail('Enter a valid cron expression, such as "0 8 * * 1-5".');
                    }
                },
            ],
            'timezone' => ['required', 'timezone:all'],
            'model' => ['nullable', 'string', 'max:255'],
            'tools' => ['present', 'array', 'max:100'],
            'tools.*' => [
                'string', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! app(ToolService::class)->isKnown($this->user(), (string) $value)) {
                        $fail('Choose tools from the list.');
                    }
                },
            ],
            'auto_approve' => ['boolean'],
            'deliver_email' => ['boolean'],
            'deliver_sms' => ['boolean'],
            'sms_to' => ['required_if_accepted:deliver_sms', 'nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sms_to.required_if_accepted' => 'Enter the number to text the result to.',
            'sms_to.regex' => 'Enter the number in international format, such as +15025550123.',
        ];
    }
}
