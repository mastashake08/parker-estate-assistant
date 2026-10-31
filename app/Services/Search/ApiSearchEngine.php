<?php

namespace App\Services\Search;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A search engine reached through a JSON API.
 */
abstract class ApiSearchEngine implements SearchEngine
{
    /**
     * The engine's name, as shown in error messages.
     */
    abstract protected function name(): string;

    /**
     * Send a request to the engine, turning failures into a SearchException.
     *
     * @param  Closure(PendingRequest): Response  $send
     *
     * @throws SearchException
     */
    protected function request(Closure $send): Response
    {
        try {
            $response = $send(Http::acceptJson()->connectTimeout(5)->timeout(20));
        } catch (ConnectionException $exception) {
            throw new SearchException("Could not reach {$this->name()}.", previous: $exception);
        }

        if ($response->failed()) {
            throw new SearchException("{$this->name()} answered with HTTP {$response->status()}.");
        }

        return $response;
    }

    /**
     * Turn the engine's result objects into results, keeping only web pages.
     *
     * Titles and snippets can contain highlighting markup, which is removed.
     *
     * @param  mixed  $items  The decoded result list from the response.
     * @return Collection<int, array{title: string, url: string, snippet: string}>
     */
    protected function results(mixed $items, string $snippetKey, int $limit): Collection
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn (mixed $item) => is_array($item)
                && is_string($item['url'] ?? null)
                && Str::startsWith($item['url'], ['http://', 'https://']))
            ->map(fn (array $item) => [
                'title' => $this->text($item['title'] ?? null) ?: $item['url'],
                'url' => $item['url'],
                'snippet' => $this->text($item[$snippetKey] ?? null),
            ])
            ->take($limit)
            ->values();
    }

    /**
     * Plain text from a value that may contain markup.
     */
    protected function text(mixed $value): string
    {
        return is_string($value) ? Str::squish(html_entity_decode(strip_tags($value))) : '';
    }
}
