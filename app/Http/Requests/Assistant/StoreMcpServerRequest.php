<?php

namespace App\Http\Requests\Assistant;

use App\Enums\McpTransport;
use App\Models\McpServer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMcpServerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->route('mcp_server');

        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('mcp_servers')
                    ->where('user_id', $this->user()->id)
                    ->ignore($server instanceof McpServer ? $server->id : null),
            ],
            'transport' => [
                'required',
                Rule::enum(McpTransport::class),
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value === McpTransport::Stdio->value && ! config('assistant.mcp.stdio_enabled')) {
                        $fail('Local (stdio) MCP servers are disabled.');
                    }
                },
            ],
            'command' => ['required_if:transport,stdio', 'nullable', 'string', 'max:255'],
            'args' => ['nullable', 'array'],
            'args.*' => ['string', 'max:1000'],
            'env' => ['nullable', 'array'],
            'env.*' => ['nullable', 'string', 'max:4000'],
            'url' => ['required_if:transport,http', 'nullable', 'url', 'max:2048'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['string', 'max:4000'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:600'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
