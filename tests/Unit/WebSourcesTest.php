<?php

use App\Services\WebSources;

test('a link can be read back from what it writes', function (string $title, string $url, string $linkedUrl) {
    $sources = new WebSources;

    expect($sources->fromToolResult('WebFetch', 'Source: '.$sources->link($title, $url)."\n\nPage text."))
        ->toBe([['title' => $title, 'url' => $linkedUrl]]);
})->with([
    'plain' => ['Opening hours', 'https://example.com/hours', 'https://example.com/hours'],
    'brackets in the title' => ['Docs [beta] \\ notes', 'https://example.com/docs', 'https://example.com/docs'],
    'parentheses and spaces in the url' => ['Wiki', 'https://en.wikipedia.org/wiki/Estate_(law)?q=a b', 'https://en.wikipedia.org/wiki/Estate_%28law%29?q=a%20b'],
    'an already-encoded url' => ['Encoded', 'https://example.com/a%20b', 'https://example.com/a%20b'],
]);

test('a page without a title is linked by its url', function () {
    expect((new WebSources)->link('', 'https://example.com/'))->toBe('[https://example.com/](https://example.com/)');
});

test('every search result is a source, once each', function () {
    $result = <<<'TEXT'
        Search results for [probate]:

        1. [First](https://example.com/1)
           Snippet mentioning 2. [Not a result](https://example.com/snippet)

        2. [Second](https://example.com/2)
           Another snippet.

        3. [First again](https://example.com/1)
           Duplicate.
        TEXT;

    expect((new WebSources)->fromToolResult('WebSearch', $result))->toBe([
        ['title' => 'First', 'url' => 'https://example.com/1'],
        ['title' => 'Second', 'url' => 'https://example.com/2'],
    ]);
});

test('only the fetched page itself is a source, not links in its text', function () {
    $result = "Source: [Page](https://example.com/)\n\nSource: [Inside the page](https://example.com/other)";

    expect((new WebSources)->fromToolResult('WebFetch', $result))
        ->toBe([['title' => 'Page', 'url' => 'https://example.com/']]);
});

test('links that are not http or https are ignored', function () {
    expect((new WebSources)->fromToolResult('WebSearch', '1. [Click](javascript:alert(1))'))->toBe([]);
});

test('other tools and failed fetches have no sources', function () {
    $sources = new WebSources;

    expect($sources->fromToolResult('CurrentDateTime', 'Source: [x](https://example.com/)'))->toBe([])
        ->and($sources->fromToolResult('WebFetch', 'Could not fetch [https://example.com/]: timed out'))->toBe([]);
});
