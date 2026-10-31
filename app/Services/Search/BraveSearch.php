<?php

namespace App\Services\Search;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

/**
 * Searches with the Brave Search API, which needs an API key.
 */
class BraveSearch extends ApiSearchEngine
{
    public function __construct(protected ?string $apiKey) {}

    /**
     * Search the web.
     *
     * @return Collection<int, array{title: string, url: string, snippet: string}>
     *
     * @throws SearchException
     */
    public function search(string $query, int $limit): Collection
    {
        if (blank($this->apiKey)) {
            throw new SearchException('Set BRAVE_SEARCH_API_KEY to use Brave Search.');
        }

        $response = $this->request(fn (PendingRequest $http) => $http
            ->withHeaders(['X-Subscription-Token' => $this->apiKey])
            ->get('https://api.search.brave.com/res/v1/web/search', ['q' => $query, 'count' => $limit]));

        return $this->results($response->json('web.results'), 'description', $limit);
    }

    protected function name(): string
    {
        return 'Brave Search';
    }
}
