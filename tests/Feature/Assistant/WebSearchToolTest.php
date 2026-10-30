<?php

use App\Ai\Tools\WebSearch;
use App\Services\Browser\Browser;
use App\Services\Browser\BrowserException;
use App\Services\Browser\RenderedPage;
use Laravel\Ai\Tools\Request;

use function Pest\Laravel\mock;

function searchResultsHtml(int $count): string
{
    $results = collect(range(1, $count))->map(fn (int $number) => <<<HTML
        <div class="result results_links web-result">
            <h2 class="result__title">
                <a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fpage-{$number}%3Fa%3D1&amp;rut=abc">Result &amp; {$number}</a>
            </h2>
            <a class="result__snippet" href="#">Snippet for <b>result</b> {$number}.</a>
        </div>
        HTML)->implode("\n");

    return "<html><body><div id=\"links\">{$results}</div></body></html>";
}

function search(string $query, ?int $limit = null): string
{
    return (string) app(WebSearch::class)->handle(new Request(array_filter(['query' => $query, 'limit' => $limit])));
}

test('it renders the search engine results page and returns the results', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->once()
        ->with('https://html.duckduckgo.com/html/?q=estate+tax+%26+probate')
        ->andReturn(new RenderedPage('https://html.duckduckgo.com/html/', 'Results', searchResultsHtml(2), ''));

    expect(search('estate tax & probate'))->toBe(<<<'TEXT'
        Search results for [estate tax & probate]:

        1. Result & 1
           https://example.com/page-1?a=1
           Snippet for result 1.

        2. Result & 2
           https://example.com/page-2?a=1
           Snippet for result 2.
        TEXT);
});

test('the number of results is limited', function (?int $limit, int $expected) {
    config(['assistant.web.search_results' => 3]);

    mock(Browser::class)
        ->shouldReceive('render')
        ->andReturn(new RenderedPage('', '', searchResultsHtml(12), ''));

    expect(substr_count(search('probate', $limit), 'https://example.com/'))->toBe($expected);
})->with([
    'default from config' => [null, 3],
    'requested by the model' => [5, 5],
    'capped at ten' => [50, 10],
]);

test('a search with no results says so', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->andReturn(new RenderedPage('', '', '<html><body>No results.</body></html>', ''));

    expect(search('xyzzy'))->toBe('No results found for [xyzzy].');
});

test('browser failures are reported to the model instead of thrown', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->andThrow(new BrowserException('Navigation timed out.'));

    expect(search('probate'))->toBe('Could not search for [probate]: Navigation timed out.');
});
