<?php

namespace App\Services\Audio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the DiffSinger service in services/diffsinger, which describes
 * the installed singing voices, serves their phoneme dictionaries, and
 * sings .ds scores.
 */
class DiffSingerClient
{
    /**
     * The installed voices, or why the service could not be reached.
     *
     * @return array{online: bool, voices: list<array{name: string, language: string|null, languages: list<string>, speakers: list<string>, speaker: string|null, variance: bool}>, error: string|null}
     */
    public function status(): array
    {
        try {
            $voices = $this->request(10)->get('voices')->throw()->json('voices', []);
        } catch (ConnectionException|RequestException $exception) {
            return ['online' => false, 'voices' => [], 'error' => $this->reason($exception)];
        }

        return ['online' => true, 'voices' => array_values(array_filter((array) $voices, is_array(...))), 'error' => null];
    }

    /**
     * Describe one voice.
     *
     * @return array{name: string, language: string|null, languages: list<string>, speakers: list<string>, speaker: string|null, variance: bool}
     *
     * @throws AudioServiceException When the voice is not installed.
     */
    public function voice(string $name): array
    {
        $status = $this->status();

        if (! $status['online']) {
            throw new AudioServiceException('DiffSinger could not be reached: '.$status['error']);
        }

        foreach ($status['voices'] as $voice) {
            if (($voice['name'] ?? null) === $name) {
                return $voice;
            }
        }

        throw new AudioServiceException("The DiffSinger voice {$name} is not installed.");
    }

    /**
     * A voice's words and their phonemes, and which phonemes are vowels.
     * Dictionaries are large and rarely change, so they are cached.
     *
     * @return array{language: string|null, entries: array<string, list<string>>, vowels: list<string>}
     *
     * @throws AudioServiceException
     */
    public function dictionary(string $voice, ?string $language = null): array
    {
        return Cache::remember(
            'diffsinger:dictionary:'.sha1($voice.'|'.$language),
            config()->integer('audio.singing.dictionary_cache_seconds'),
            function () use ($voice, $language) {
                try {
                    $dictionary = $this->request(30)
                        ->get('voices/'.rawurlencode($voice).'/dictionary', array_filter(['language' => $language]))
                        ->throw()
                        ->json();
                } catch (ConnectionException|RequestException $exception) {
                    throw new AudioServiceException('DiffSinger could not give the voice\'s dictionary: '.$this->reason($exception), previous: $exception);
                }

                return [
                    'language' => $dictionary['language'] ?? $language,
                    'entries' => (array) ($dictionary['entries'] ?? []),
                    'vowels' => array_values((array) ($dictionary['vowels'] ?? [])),
                ];
            },
        );
    }

    /**
     * Sing a score, as a WAV file.
     *
     * @param  list<array<string, mixed>>  $segments  The .ds file's segments.
     *
     * @throws AudioServiceException
     */
    public function render(string $voice, array $segments, ?string $speaker = null): string
    {
        try {
            $wav = $this->request(config()->integer('audio.singing.timeout'))
                ->withHeaders(['Accept' => 'audio/wav'])
                ->post('render', array_filter([
                    'voice' => $voice,
                    'ds' => $segments,
                    'speaker' => $speaker,
                ]))
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException $exception) {
            throw new AudioServiceException('DiffSinger could not sing the lyrics: '.$this->reason($exception), previous: $exception);
        }

        if ($wav === '') {
            throw new AudioServiceException('DiffSinger returned no audio.');
        }

        return $wav;
    }

    /**
     * A request to the service.
     */
    protected function request(int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('audio.singing.url'), '/').'/')
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
