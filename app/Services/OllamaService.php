<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class OllamaService
{
    /**
     * List the models installed in Ollama, by name.
     *
     * Returns nothing when the assistant uses another provider or Ollama
     * cannot be reached, so the chat falls back to the default model.
     *
     * @return list<string>
     */
    public function installedModels(): array
    {
        if (config('assistant.provider') !== 'ollama') {
            return [];
        }

        try {
            $names = Http::baseUrl((string) config('ai.providers.ollama.url'))
                ->connectTimeout(2)
                ->timeout(5)
                ->get('api/tags')
                ->throw()
                ->json('models.*.name', []);
        } catch (ConnectionException|RequestException) {
            return [];
        }

        return array_values(array_filter($names, is_string(...)));
    }
}
