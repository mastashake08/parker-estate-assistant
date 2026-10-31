<?php

use App\Services\Browser\BrowserException;
use App\Services\Browser\ChromeBrowser;
use App\Services\Browser\WebSocketClient;

test('a remote browser endpoint without a path is connected to at its root', function () {
    $browser = new ChromeBrowser(endpoint: 'ws://127.0.0.1:9', timeout: 1);

    expect(fn () => $browser->render('https://example.com'))
        ->toThrow(function (BrowserException $exception) {
            expect($exception->getMessage())
                ->toStartWith('Could not start the headless browser:')
                ->not->toContain('Invalid path');
        });
});

/**
 * Start a WebSocket server that takes 6 seconds to answer the handshake, like
 * browserless launching a fresh Chrome on a busy machine, and return its port.
 *
 * @return array{0: int, 1: resource}
 */
function slowWebSocketServer(): array
{
    $script = <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        fwrite(STDOUT, parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT)."\n");
        $client = stream_socket_accept($server, 30);
        preg_match('/Sec-WebSocket-Key: (\S+)/i', fread($client, 4096), $key);
        sleep(6);
        $accept = base64_encode(sha1($key[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
        sleep(2);
        PHP;

    $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);

    return [(int) fgets($pipes[1]), $process];
}

test('the WebSocket client waits longer than 5 seconds for a slow handshake', function () {
    [$port, $process] = slowWebSocketServer();

    try {
        $client = new WebSocketClient("ws://127.0.0.1:{$port}/", 'http://127.0.0.1', handshakeTimeout: 10);

        expect($client->connect())->toBeTrue();
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
});
