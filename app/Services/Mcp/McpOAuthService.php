<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Exceptions\OAuthException;
use Laravel\Mcp\Client\OAuth\AuthServerDiscovery;
use Laravel\Mcp\Client\OAuth\OAuthClient;
use Laravel\Mcp\Client\OAuth\TokenSet;

class McpOAuthService
{
    /**
     * Refresh an access token this many seconds before it expires.
     */
    protected const REFRESH_LEEWAY_SECONDS = 60;

    public function __construct(protected AuthServerDiscovery $discovery) {}

    /**
     * Start signing in to the server: discover its authorization server,
     * register this app if needed, and get the URL to send the user to.
     *
     * @throws OAuthException
     */
    public function authorizationUrl(McpServer $server): string
    {
        return $this->oauthClient($server)->redirect()->getTargetUrl();
    }

    /**
     * Finish signing in by exchanging the callback's code for tokens.
     *
     * Saving the tokens updates the server, so its tools are listed again.
     *
     * @throws OAuthException
     */
    public function completeSignIn(McpServer $server): void
    {
        $server->forceFill(['oauth_tokens' => $this->tokens($this->oauthClient($server)->exchangeCallback())])->save();
    }

    /**
     * Forget the server's tokens.
     */
    public function signOut(McpServer $server): void
    {
        $server->forceFill(['oauth_tokens' => null])->save();
    }

    /**
     * Get a current access token for the server, refreshing it when it is about to expire.
     *
     * When the refresh fails the old token is still sent, so the server
     * answers that sign-in is required and the user can sign in again.
     */
    public function accessToken(McpServer $server): ?string
    {
        $tokens = $server->oauth_tokens;

        if ($tokens === null) {
            return null;
        }

        $expiresSoon = $tokens['expires_at'] !== null && time() >= $tokens['expires_at'] - self::REFRESH_LEEWAY_SECONDS;

        if (! $expiresSoon || $tokens['refresh_token'] === null) {
            return $tokens['access_token'];
        }

        try {
            $refreshed = $this->oauthClient($server)->refreshCredentials(
                $tokens['refresh_token'],
                $tokens['client_id'],
                $tokens['client_secret'],
            );
        } catch (OAuthException) {
            return $tokens['access_token'];
        }

        // Keep the refresh token when the server does not rotate it.
        $refreshed->refreshToken ??= $tokens['refresh_token'];

        // Refreshing is not an edit, so the cached tool list is kept.
        McpServer::withoutTimestamps(fn () => $server->forceFill(['oauth_tokens' => $this->tokens($refreshed)])->save());

        return $refreshed->accessToken;
    }

    /**
     * Build the OAuth client for the server, returning to the server's callback route.
     *
     * @throws OAuthException
     */
    protected function oauthClient(McpServer $server): OAuthClient
    {
        return Client::web((string) $server->url)
            ->withHeaders($server->headers ?? [])
            ->withOAuth(
                clientId: $server->oauth_client_id,
                clientSecret: $server->oauth_client_secret,
                scope: $server->oauth_scope ?? $this->advertisedScope($server),
                redirectUri: route('mcp-servers.oauth.callback', $server),
            )
            ->oAuthClient();
    }

    /**
     * The scopes the server advertises, for when the user has not entered any.
     *
     * Without them the client asks for "mcp:use", which servers such as
     * Stripe's reject.
     *
     * @throws OAuthException
     */
    protected function advertisedScope(McpServer $server): ?string
    {
        $scopes = $this->discovery->discover((string) $server->url)->scopesSupported;

        return $scopes === [] ? null : implode(' ', $scopes);
    }

    /**
     * The token fields kept for the server.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: int|null, client_id: string|null, client_secret: string|null}
     */
    protected function tokens(TokenSet $token): array
    {
        return [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'client_id' => $token->clientId,
            'client_secret' => $token->clientSecret,
        ];
    }
}
