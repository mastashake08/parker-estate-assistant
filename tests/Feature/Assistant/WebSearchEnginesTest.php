<?php

use App\Ai\Tools\WebSearch;
use App\Services\Search\BraveSearch;
use App\Services\Search\DuckDuckGoSearch;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearxngSearch;
use App\Services\Search\TavilySearch;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function searchWith(string $engine, string $query, ?int $limit = null): string
{
    config(['assistant.web.search_engine' => $engine]);

    return (string) app(WebSearch::class)->handle(new Request(array_filter(['query' => $query, 'limit' => $limit])));
}

test('the configured engine is used', function (string $engine, string $class) {
    config(['assistant.web.search_engine' => $engine]);

    expect(app(SearchEngine::class))->toBeInstanceOf($class);
})->with([
    'duckduckgo' => ['duckduckgo', DuckDuckGoSearch::class],
    'searxng' => ['searxng', SearxngSearch::class],
    'brave' => ['brave', BraveSearch::class],
    'tavily' => ['tavily', TavilySearch::class],
]);

test('an unknown engine is rejected', function () {
    config(['assistant.web.search_engine' => 'altavista']);

    app(SearchEngine::class);
})->throws(InvalidArgumentException::class, 'Unknown web search engine [altavista]');

test('SearXNG results are read from its JSON API', function () {
    config(['assistant.web.searxng_url' => 'http://searxng:8080/']);
    Http::fake(['http://searxng:8080/search*' => Http::response(['results' => [
        ['title' => 'Probate  guide', 'url' => 'https://example.com/probate', 'content' => 'All about <b>probate</b>.'],
        ['title' => 'Not a page', 'url' => 'ftp://example.com/file', 'content' => ''],
        ['title' => 'Estate tax', 'url' => 'https://example.com/tax', 'content' => 'Tax rules.'],
    ]])]);

    expect(searchWith('searxng', 'estate & probate'))->toBe(<<<'TEXT'
        Search results for [estate & probate]:

        1. [Probate guide](https://example.com/probate)
           All about probate.

        2. [Estate tax](https://example.com/tax)
           Tax rules.
        TEXT);

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://searxng:8080/search?q=estate%20%26%20probate&format=json');
});

test('Brave Search sends the API key and reads web results', function () {
    config(['assistant.web.brave_api_key' => 'brave-key']);
    Http::fake(['https://api.search.brave.com/*' => Http::response(['web' => ['results' => [
        ['title' => 'Probate', 'url' => 'https://example.com/probate', 'description' => 'What <strong>probate</strong> is.'],
    ]]])]);

    expect(searchWith('brave', 'probate', 3))->toContain("1. [Probate](https://example.com/probate)\n   What probate is.");

    Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://api.search.brave.com/res/v1/web/search?')
        && $request['q'] === 'probate'
        && $request['count'] === 3
        && $request->hasHeader('X-Subscription-Token', 'brave-key'));
});

test('Tavily sends the API key and reads results', function () {
    config(['assistant.web.tavily_api_key' => 'tvly-key']);
    Http::fake(['https://api.tavily.com/search' => Http::response(['results' => [
        ['title' => 'Probate', 'url' => 'https://example.com/probate', 'content' => 'Probate explained.'],
    ]])]);

    expect(searchWith('tavily', 'probate', 2))->toContain("1. [Probate](https://example.com/probate)\n   Probate explained.");

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
        && $request['query'] === 'probate'
        && $request['max_results'] === 2
        && $request->hasHeader('Authorization', 'Bearer tvly-key'));
});

test('API engines return at most the requested number of results', function () {
    config(['assistant.web.searxng_url' => 'http://searxng:8080']);
    Http::fake(['*' => Http::response(['results' => collect(range(1, 12))
        ->map(fn (int $number) => ['title' => "Result {$number}", 'url' => "https://example.com/{$number}", 'content' => ''])
        ->all()])]);

    expect(substr_count(searchWith('searxng', 'probate', 4), 'https://example.com/'))->toBe(4);
});

test('an engine without its API key or URL tells the model what is missing', function (string $engine, string $message) {
    Http::fake();

    expect(searchWith($engine, 'probate'))->toBe("Could not search for [probate]: {$message}");

    Http::assertNothingSent();
})->with([
    'searxng' => ['searxng', 'Set SEARXNG_URL to use SearXNG.'],
    'brave' => ['brave', 'Set BRAVE_SEARCH_API_KEY to use Brave Search.'],
    'tavily' => ['tavily', 'Set TAVILY_API_KEY to use Tavily.'],
]);

test('API errors are reported to the model instead of thrown', function () {
    config(['assistant.web.brave_api_key' => 'wrong-key']);
    Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

    expect(searchWith('brave', 'probate'))->toBe('Could not search for [probate]: Brave Search answered with HTTP 401.');
});

test('an unreachable engine is reported to the model', function () {
    config(['assistant.web.searxng_url' => 'http://searxng:8080']);
    Http::fake(['*' => Http::failedConnection()]);

    expect(searchWith('searxng', 'probate'))->toBe('Could not search for [probate]: Could not reach SearXNG.');
});
