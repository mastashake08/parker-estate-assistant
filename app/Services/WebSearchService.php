<?php

namespace App\Services;

use App\Services\Browser\Browser;
use App\Services\Browser\BrowserException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebSearchService
{
    /**
     * The most results the model may ask for in one search.
     */
    public const MAX_RESULTS = 10;

    public function __construct(
        protected Browser $browser,
        protected WebSources $sources,
    ) {}

    /**
     * Search the web in a headless browser and describe the results as text for the model.
     */
    public function search(string $query, ?int $limit = null): string
    {
        $limit = min($limit ?? (int) config('assistant.web.search_results'), self::MAX_RESULTS);

        try {
            $page = $this->browser->render(sprintf(config('assistant.web.search_url'), urlencode($query)));
        } catch (BrowserException $exception) {
            return "Could not search for [{$query}]: {$exception->getMessage()}";
        }

        $results = $this->parse($page->html)->take(max($limit, 1));

        if ($results->isEmpty()) {
            return "No results found for [{$query}].";
        }

        return "Search results for [{$query}]:\n\n".$results
            ->map(fn (array $result, int $index) => ($index + 1).'. '.$this->sources->link($result['title'], $result['url'])."\n   {$result['snippet']}")
            ->implode("\n\n");
    }

    /**
     * Extract the results from a DuckDuckGo HTML results page.
     *
     * @return Collection<int, array{title: string, url: string, snippet: string}>
     */
    protected function parse(string $html): Collection
    {
        if (trim($html) === '') {
            return collect();
        }

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $results = [];

        foreach ($this->query($xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' result ')]") as $result) {
            $link = $this->query($xpath, ".//a[contains(@class, 'result__a')]", $result)[0] ?? null;
            $snippet = $this->query($xpath, ".//*[contains(@class, 'result__snippet')]", $result)[0] ?? null;

            if (! $link instanceof DOMElement) {
                continue;
            }

            $url = $this->resolveUrl($link->getAttribute('href'));

            if (Str::startsWith($url, ['http://', 'https://'])) {
                $results[] = [
                    'title' => Str::squish($link->textContent),
                    'url' => $url,
                    'snippet' => Str::squish($snippet->textContent ?? ''),
                ];
            }
        }

        return collect($results);
    }

    /**
     * Run an XPath query for elements, treating a malformed expression as no matches.
     *
     * @return list<DOMElement>
     */
    protected function query(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false) {
            return [];
        }

        return array_values(array_filter(
            iterator_to_array($nodes),
            fn (mixed $node) => $node instanceof DOMElement,
        ));
    }

    /**
     * Unwrap DuckDuckGo's click-tracking redirect to get the result's real URL.
     */
    protected function resolveUrl(string $href): string
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        return is_string($query['uddg'] ?? null) ? $query['uddg'] : $href;
    }
}
