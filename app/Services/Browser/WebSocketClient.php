<?php

namespace App\Services\Browser;

use Exception;
use Wrench\Client;

/**
 * Wrench's WebSocket client, with a configurable wait for the handshake.
 *
 * Wrench always waits 5 seconds for the handshake response, which is too
 * short for browserless to launch a new Chrome on a busy machine.
 */
class WebSocketClient extends Client
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(string $uri, string $origin, protected float $handshakeTimeout, array $options = [])
    {
        parent::__construct($uri, $origin, $options);
    }

    /**
     * Connect and complete the WebSocket handshake, waiting up to the handshake timeout for the response.
     */
    public function connect(): bool
    {
        if ($this->isConnected()) {
            return false;
        }

        try {
            $this->socket->connect();
        } catch (Exception) {
            return false;
        }

        $key = $this->protocol->generateKey();

        $this->socket->send($this->protocol->getRequestHandshake($this->uri, $key, $this->origin, $this->headers));

        $response = $this->socket->receive(self::MAX_HANDSHAKE_RESPONSE, $this->handshakeTimeout);

        return $this->connected = $this->protocol->validateResponseHandshake($response, $key);
    }
}
