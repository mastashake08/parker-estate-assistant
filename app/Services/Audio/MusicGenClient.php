<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the MusicGen service in services/musicgen, which keeps one of
 * Meta's MusicGen models in memory and generates WAV files from prompts.
 */
class MusicGenClient
{
    /**
     * The service's state: the model it has loaded, the one it is loading,
     * and the models it can load.
     *
     * @return array{online: bool, loaded: string|null, loading: string|null, device: string|null, models: list<string>, error: string|null}
     */
    public function status(): array
    {
        $models = config('audio.music.models');

        try {
            $status = $this->request(5)->get('models')->throw()->json();
        } catch (ConnectionException|RequestException $exception) {
            return ['online' => false, 'loaded' => null, 'loading' => null, 'device' => null, 'models' => $models, 'error' => $this->reason($exception)];
        }

        return [
            'online' => true,
            'loaded' => is_string($status['loaded'] ?? null) ? $status['loaded'] : null,
            'loading' => is_string($status['loading'] ?? null) ? $status['loading'] : null,
            'device' => is_string($status['device'] ?? null) ? $status['device'] : null,
            'models' => $models,
            'error' => is_string($status['error'] ?? null) ? $status['error'] : null,
        ];
    }

    /**
     * Start loading a model, replacing the one in memory. The service
     * downloads it first if it must, so this returns before it is ready.
     *
     * @throws AudioServiceException
     */
    public function load(string $model): void
    {
        try {
            $this->request(30)->post('models/load', ['model' => $model])->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new AudioServiceException('MusicGen could not load the model: '.$this->reason($exception), previous: $exception);
        }
    }

    /**
     * Generate music from a description, as a WAV file. The model is loaded first if it is not already.
     *
     * @throws AudioServiceException
     */
    public function generate(string $prompt, string $model, int $seconds, float $guidance = 3.0): string
    {
        try {
            $wav = $this->request((int) config('audio.music.timeout'))
                ->withHeaders(['Accept' => 'audio/wav'])
                ->post('generate', [
                    'prompt' => $prompt,
                    'model' => $model,
                    'duration' => $seconds,
                    'guidance_scale' => $guidance,
                ])
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException $exception) {
            throw new AudioServiceException('MusicGen could not generate the music: '.$this->reason($exception), previous: $exception);
        }

        if ($wav === '') {
            throw new AudioServiceException('MusicGen returned no audio.');
        }

        return $wav;
    }

    /**
     * A request to the service.
     */
    protected function request(int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('audio.music.url'), '/').'/')
            ->connectTimeout(3)
            ->timeout($timeout)
            ->acceptJson();
    }

    /**
     * Why a request failed, in words: the service's "detail" message if it gave one.
     */
    protected function reason(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'the service could not be reached.';
        }

        $detail = $exception->response->json('detail');

        return is_string($detail) && $detail !== ''
            ? $detail
            : "the service answered with HTTP {$exception->response->status()}.";
    }
}
