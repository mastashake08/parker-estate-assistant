<?php

namespace App\Services\Search;

use App\Services\Browser\Browser;
use App\Services\Browser\BrowserException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Searches by rendering DuckDuckGo's HTML results page in the headless browser.
 *
 * It needs no account, but it is not an official API and may be blocked
 * under heavy use.
 */
class DuckDuckGoSearch implements SearchEngine
{
    public function __construct(
        protected Browser $browser,
        protected string $url,
    ) {}

    /**
     * Search the web.
     *
     * @return Collection<int, array{title: string, url: string, snippet: string}>
     *
     * @throws SearchException
     */
    public function search(string $query, int $limit): Collection
    {
        try {
            $page = $this->browser->render(sprintf($this->url, urlencode($query)));
        } catch (BrowserException $exception) {
            throw new SearchException($exception->getMessage(), previous: $exception);
        }

        return $this->parse($page->html)->take($limit);
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
