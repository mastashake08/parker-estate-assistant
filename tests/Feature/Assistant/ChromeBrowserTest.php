<?php

use App\Services\Browser\BrowserException;
use App\Services\Browser\ChromeBrowser;

test('a remote browser endpoint without a path is connected to at its root', function () {
    $browser = new ChromeBrowser(endpoint: 'ws://127.0.0.1:9', timeout: 1);

    expect(fn () => $browser->render('https://example.com'))
        ->toThrow(function (BrowserException $exception) {
            expect($exception->getMessage())
                ->toStartWith('Could not start the headless browser:')
                ->not->toContain('Invalid path');
        });
});
