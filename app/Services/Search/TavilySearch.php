<?php

namespace App\Services\Search;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

/**
 * Searches with the Tavily API, which needs an API key.
 */
class TavilySearch extends ApiSearchEngine
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
            throw new SearchException('Set TAVILY_API_KEY to use Tavily.');
        }

        $response = $this->request(fn (PendingRequest $http) => $http
            ->withToken((string) $this->apiKey)
            ->post('https://api.tavily.com/search', ['query' => $query, 'max_results' => $limit]));

        return $this->results($response->json('results'), 'content', $limit);
    }

    protected function name(): string
    {
        return 'Tavily';
    }
}
