<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Phonon text-to-speech servers (ptts-openai-server), each of
 * which serves one loaded model through an OpenAI-compatible API.
 */
class PhononClient
{
    /**
     * Phonon's PCM output: 16-bit mono at 24 kHz.
     */
    public const SAMPLE_RATE = 24000;

    /**
     * Describe each configured server: the model it has loaded, its
     * languages and voices, or why it could not be reached.
     *
     * @return list<array{url: string, online: bool, model: string|null, languages: list<string>, voices: list<string>, error: string|null}>
     */
    public function servers(): array
    {
        return array_values(array_map($this->describe(...), config()->array('audio.speech.servers')));
    }

    /**
     * Describe one server.
     *
     * @return array{url: string, online: bool, model: string|null, languages: list<string>, voices: list<string>, error: string|null}
     */
    public function describe(string $url): array
    {
        try {
            $model = $this->request($url, 5)->get('v1/models')->throw()->json('data.0', []);
            $voices = $this->request($url, 5)->get('v1/audio/voices')->throw()->json('voices', []);
        } catch (ConnectionException|RequestException $exception) {
            return ['url' => $url, 'online' => false, 'model' => null, 'languages' => [], 'voices' => [], 'error' => $this->reason($exception)];
        }

        return [
            'url' => $url,
            'online' => true,
            'model' => is_string($model['id'] ?? null) ? $model['id'] : null,
            'languages' => array_values(array_filter((array) ($model['languages'] ?? []), is_string(...))),
            'voices' => array_values(array_filter((array) $voices, is_string(...))),
            'error' => null,
        ];
    }

    /**
     * The name of the model a server has loaded.
     */
    public function model(string $url): ?string
    {
        return $this->describe($url)['model'];
    }

    /**
     * Speak the text with one of the server's voices, as a WAV file.
     *
     * Phonon streams its WAV output without a length in the header, which
     * browsers may refuse to decode, so raw PCM is asked for and wrapped.
     *
     * @throws AudioServiceException
     */
    public function synthesize(string $url, string $text, ?string $voice = null): string
    {
        try {
            $pcm = $this->request($url, (int) config('audio.speech.timeout'))
                ->post('v1/audio/speech', [
                    'input' => $text,
                    'voice' => $voice ?: 'default',
                    'response_format' => 'pcm',
                ])
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException $exception) {
            throw new AudioServiceException('Phonon could not speak the text: '.$this->reason($exception), previous: $exception);
        }

        if ($pcm === '') {
            throw new AudioServiceException('Phonon returned no audio.');
        }

        return Wav::fromPcm16($pcm, self::SAMPLE_RATE);
    }

    /**
     * A request to one server.
     */
    protected function request(string $url, int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim($url, '/').'/')->connectTimeout(3)->timeout($timeout)->acceptJson();
    }

    /**
     * Why a request failed, in words: the server's OpenAI-style error message if it gave one.
     */
    protected function reason(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'the server could not be reached.';
        }

        $response = $exception->response;

        return $this->message($response) ?? "the server answered with HTTP {$response->status()}.";
    }

    /**
     * The error message in an OpenAI-style error response.
     */
    protected function message(Response $response): ?string
    {
        $message = $response->json('error.message');

        return is_string($message) && $message !== '' ? $message : null;
    }
}
