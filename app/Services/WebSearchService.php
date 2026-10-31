<?php

namespace App\Services;

use App\Services\Search\SearchEngine;
use App\Services\Search\SearchException;

class WebSearchService
{
    /**
     * The most results the model may ask for in one search.
     */
    public const MAX_RESULTS = 10;

    public function __construct(
        protected SearchEngine $engine,
        protected WebSources $sources,
    ) {}

    /**
     * Search the web with the configured engine and describe the results as text for the model.
     */
    public function search(string $query, ?int $limit = null): string
    {
        $limit = max(min($limit ?? (int) config('assistant.web.search_results'), self::MAX_RESULTS), 1);

        try {
            $results = $this->engine->search($query, $limit);
        } catch (SearchException $exception) {
            return "Could not search for [{$query}]: {$exception->getMessage()}";
        }

        if ($results->isEmpty()) {
            return "No results found for [{$query}].";
        }

        return "Search results for [{$query}]:\n\n".$results
            ->values()
            ->map(fn (array $result, int $index) => ($index + 1).'. '.$this->sources->link($result['title'], $result['url'])."\n   {$result['snippet']}")
            ->implode("\n\n");
    }
}
