<?php

namespace App\Services\Search;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

/**
 * Searches a SearXNG instance through its JSON API.
 *
 * SearXNG must allow the "json" format (search.formats in its settings.yml).
 */
class SearxngSearch extends ApiSearchEngine
{
    public function __construct(protected ?string $url) {}

    /**
     * Search the web.
     *
     * @return Collection<int, array{title: string, url: string, snippet: string}>
     *
     * @throws SearchException
     */
    public function search(string $query, int $limit): Collection
    {
        if (blank($this->url)) {
            throw new SearchException('Set SEARXNG_URL to use SearXNG.');
        }

        $response = $this->request(fn (PendingRequest $http) => $http->get(
            rtrim((string) $this->url, '/').'/search',
            ['q' => $query, 'format' => 'json'],
        ));

        return $this->results($response->json('results'), 'content', $limit);
    }

    protected function name(): string
    {
        return 'SearXNG';
    }
}
