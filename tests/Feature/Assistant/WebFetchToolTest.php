<?php

use App\Ai\Tools\WebFetch;
use App\Services\Browser\Browser;
use App\Services\Browser\BrowserException;
use App\Services\Browser\RenderedPage;
use Laravel\Ai\Tools\Request;

use function Pest\Laravel\mock;

function fetchPage(string $url): string
{
    return (string) app(WebFetch::class)->handle(new Request(['url' => $url]));
}

test('it renders the page in the browser and returns its readable text', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->once()
        ->with('https://1.1.1.1/docs')
        ->andReturn(new RenderedPage(
            url: 'https://1.1.1.1/docs/',
            title: 'Docs',
            html: '<html><body><main>Rendered by JavaScript</main></body></html>',
            text: 'Rendered by JavaScript',
        ));

    expect(fetchPage('https://1.1.1.1/docs'))
        ->toBe("URL: https://1.1.1.1/docs/\nTitle: Docs\n\nRendered by JavaScript");
});

test('long pages are truncated', function () {
    config(['assistant.web.max_characters' => 10]);

    mock(Browser::class)
        ->shouldReceive('render')
        ->andReturn(new RenderedPage('https://1.1.1.1/', 'Long', '', str_repeat('a', 25)));

    expect(fetchPage('https://1.1.1.1/'))
        ->toBe("URL: https://1.1.1.1/\nTitle: Long\n\naaaaaaaaaa\n\n[Truncated: showing the first 10 of 25 characters.]");
});

test('only public http and https addresses may be fetched', function (string $url) {
    mock(Browser::class)->shouldNotReceive('render');

    expect(fetchPage($url))
        ->toBe("Refusing to fetch [{$url}]: only public http(s) addresses are allowed.");
})->with([
    'file scheme' => 'file:///etc/passwd',
    'javascript scheme' => 'javascript:alert(1)',
    'loopback' => 'http://127.0.0.1:8000/admin',
    'localhost' => 'http://localhost/',
    'private network' => 'http://10.0.0.5/',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'ipv6 loopback' => 'http://[::1]/',
    'docker service name' => 'http://mysql:3306/',
]);

test('content is withheld when the page redirects to a private address', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->andReturn(new RenderedPage('http://192.168.1.1/router', 'Router', '', 'admin panel'));

    expect(fetchPage('https://1.1.1.1/redirect'))
        ->toBe('Refusing to fetch [https://1.1.1.1/redirect]: it redirected to a non-public address.');
});

test('browser failures are reported to the model instead of thrown', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->andThrow(new BrowserException('net::ERR_NAME_NOT_RESOLVED'));

    expect(fetchPage('https://1.1.1.1/'))
        ->toBe('Could not fetch [https://1.1.1.1/]: net::ERR_NAME_NOT_RESOLVED');
});
