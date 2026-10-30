<?php

use App\Models\McpServer;
use App\Models\User;
use App\Services\Mcp\ClientMcpConnector;
use App\Services\Mcp\McpOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Client\OAuth\AuthServerDiscovery;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Discovery refuses private addresses, so give the fake hosts a public one.
    AuthServerDiscovery::resolveHostsUsing(fn () => ['93.184.216.34']);

    Http::fake([
        'https://mcp.acme.com/.well-known/oauth-protected-resource/mcp' => Http::response([
            'resource' => 'https://mcp.acme.com/mcp',
            'authorization_servers' => ['https://auth.acme.com'],
        ]),
        'https://auth.acme.com/.well-known/oauth-authorization-server' => Http::response([
            'issuer' => 'https://auth.acme.com',
            'authorization_endpoint' => 'https://auth.acme.com/authorize',
            'token_endpoint' => 'https://auth.acme.com/token',
            'registration_endpoint' => 'https://auth.acme.com/register',
            'code_challenge_methods_supported' => ['S256'],
        ]),
        'https://auth.acme.com/register' => fn () => $this->registrationResponse ?? Http::response(['client_id' => 'registered-client']),
        'https://auth.acme.com/token' => fn () => $this->tokenResponse ?? Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ]),
        'https://mcp.acme.com/mcp' => Http::response('', 401),
    ]);
});

afterEach(function () {
    AuthServerDiscovery::resolveHostsUsing(null);
});

/**
 * Start signing in and return the query of the URL the user is sent to.
 *
 * @return array<string, string>
 */
function startSignIn(User $user, McpServer $server): array
{
    $location = test()->actingAs($user)
        ->post(route('mcp-servers.oauth.store', $server))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith('https://auth.acme.com/authorize?');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    return $query;
}

test('signing in registers the app and sends the user to the server\'s sign-in page', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->http()->create();

    $query = startSignIn($user, $server);

    expect($query)->toMatchArray([
        'response_type' => 'code',
        'client_id' => 'registered-client',
        'redirect_uri' => route('mcp-servers.oauth.callback', $server),
        'code_challenge_method' => 'S256',
        'resource' => 'https://mcp.acme.com/mcp',
    ]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.acme.com/register'
        && $request['redirect_uris'] === [route('mcp-servers.oauth.callback', $server)]);
});

test('a client ID entered for the server is used instead of registering', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->http()->create(['oauth_client_id' => 'my-client', 'oauth_scope' => 'read write']);

    $query = startSignIn($user, $server);

    expect($query['client_id'])->toBe('my-client')
        ->and($query['scope'])->toBe('read write');
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://auth.acme.com/register');
});

test('the callback exchanges the code and keeps the tokens encrypted', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->http()->create();
    $query = startSignIn($user, $server);

    $this->actingAs($user)
        ->get(route('mcp-servers.oauth.callback', [$server, 'code' => 'the-code', 'state' => $query['state']]))
        ->assertRedirect(route('mcp-servers.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Signed in to {$server->name}."]);

    $server->refresh();

    expect($server->signed_in)->toBeTrue()
        ->and($server->oauth_tokens)->toMatchArray([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'client_id' => 'registered-client',
        ])
        ->and(DB::table('mcp_servers')->value('oauth_tokens'))->not->toContain('new-access');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.acme.com/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'the-code'
        && filled($request['code_verifier']));
});

test('a callback with the wrong state is rejected', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->http()->create();
    startSignIn($user, $server);

    $this->actingAs($user)
        ->get(route('mcp-servers.oauth.callback', [$server, 'code' => 'the-code', 'state' => 'forged']))
        ->assertRedirect(route('mcp-servers.index'))
        ->assertInertiaFlash('toast.type', 'error');

    expect($server->refresh()->signed_in)->toBeFalse();
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://auth.acme.com/token');
});

test('a server without OAuth shows why signing in failed', function () {
    Http::fake([
        'https://plain.acme.com/*' => Http::response('', 404),
    ]);
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->http()->create(['url' => 'https://plain.acme.com/mcp']);

    $this->actingAs($user)
        ->post(route('mcp-servers.oauth.store', $server))
        ->assertRedirect(route('mcp-servers.index'))
        ->assertInertiaFlash('toast.type', 'error');
});

test('signing out forgets the tokens', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->signedIn()->create();

    $this->actingAs($user)
        ->delete(route('mcp-servers.oauth.destroy', $server))
        ->assertRedirect(route('mcp-servers.index'));

    expect($server->refresh()->oauth_tokens)->toBeNull();
});

test("users cannot sign in to or out of another user's server", function () {
    $server = McpServer::factory()->signedIn()->create();
    $this->actingAs(User::factory()->create());

    $this->post(route('mcp-servers.oauth.store', $server))->assertNotFound();
    $this->get(route('mcp-servers.oauth.callback', [$server, 'code' => 'x', 'state' => 'y']))->assertNotFound();
    $this->delete(route('mcp-servers.oauth.destroy', $server))->assertNotFound();

    expect($server->refresh()->signed_in)->toBeTrue();
});

test('local servers cannot be signed in to', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('mcp-servers.oauth.store', $server))
        ->assertNotFound();
});

test('the tokens and client secret are never sent to the browser', function () {
    $user = User::factory()->create();
    McpServer::factory()->for($user)->signedIn()->create(['oauth_client_id' => 'my-client', 'oauth_client_secret' => 'shh']);

    $this->actingAs($user)
        ->get(route('mcp-servers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('servers.0.signed_in', true)
            ->where('servers.0.oauth_client_id', 'my-client')
            ->missing('servers.0.oauth_tokens')
            ->missing('servers.0.oauth_client_secret')
        );
});

test('changing the URL signs out, but renaming the server does not', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->signedIn()->create(['name' => 'acme']);

    $this->actingAs($user)->put(route('mcp-servers.update', $server), [
        'name' => 'acme tools', 'transport' => 'http', 'url' => 'https://mcp.acme.com/mcp',
    ]);

    expect($server->refresh()->signed_in)->toBeTrue();

    $this->actingAs($user)->put(route('mcp-servers.update', $server), [
        'name' => 'acme tools', 'transport' => 'http', 'url' => 'https://other.acme.com/mcp',
    ]);

    expect($server->refresh()->signed_in)->toBeFalse();
});

test('the access token is sent to the server', function () {
    $server = McpServer::factory()->signedIn()->create();

    expect(fn () => app(ClientMcpConnector::class)->listTools($server))
        ->toThrow(AuthorizationRequiredException::class);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://mcp.acme.com/mcp'
        && $request->hasHeader('Authorization', 'Bearer access-1'));
});

test('an expiring access token is refreshed without relisting the server\'s tools', function () {
    $server = McpServer::factory()->signedIn()->create();
    $server->forceFill(['oauth_tokens' => [...$server->oauth_tokens, 'expires_at' => now()->addSeconds(30)->getTimestamp()]])->save();
    $updatedAt = $server->refresh()->updated_at;

    $this->travel(1)->minutes();

    expect(app(McpOAuthService::class)->accessToken($server))->toBe('new-access');

    $server->refresh();

    expect($server->oauth_tokens)->toMatchArray(['access_token' => 'new-access', 'refresh_token' => 'new-refresh'])
        ->and($server->updated_at->equalTo($updatedAt))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.acme.com/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-1'
        && $request['client_id'] === 'registered-client');
});

test('a token that is not expiring is used as it is', function () {
    $server = McpServer::factory()->signedIn()->create();

    expect(app(McpOAuthService::class)->accessToken($server))->toBe('access-1');
    Http::assertNothingSent();
});

test('a failed refresh still sends the old token so the user is asked to sign in again', function () {
    $this->tokenResponse = Http::response(['error' => 'invalid_grant'], 400);
    $server = McpServer::factory()->signedIn()->create();
    $server->forceFill(['oauth_tokens' => [...$server->oauth_tokens, 'expires_at' => now()->subMinute()->getTimestamp()]])->save();

    expect(app(McpOAuthService::class)->accessToken($server))->toBe('access-1');
});

test('the tools page asks the user to sign in when a server requires it', function () {
    $user = User::factory()->create();
    McpServer::factory()->for($user)->http()->create();

    fakeMcpConnector()->shouldReceive('listTools')->andThrow(new AuthorizationRequiredException);

    $this->actingAs($user)
        ->get(route('tools.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('servers.0.signInRequired', true)
            ->where('servers.0.error', 'Sign in to this server to use its tools.')
        );
});

test('a failed sign-in from a plain http address other than localhost suggests using localhost', function () {
    $this->registrationResponse = Http::response(['error' => 'invalid_redirect_uri'], 400);
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->http()->create();

    $this->actingAs($user)
        ->post('http://192.168.0.60/settings/mcp-servers/'.$server->id.'/oauth')
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Could not sign in: Dynamic client registration failed with status [400]. Most sign-in providers only accept https addresses or localhost, so open the app at http://localhost and sign in from there.');
});

test('a failed sign-in from localhost does not suggest localhost', function () {
    $this->registrationResponse = Http::response(['error' => 'invalid_redirect_uri'], 400);
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->http()->create();

    $this->actingAs($user)
        ->post('http://localhost/settings/mcp-servers/'.$server->id.'/oauth')
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Could not sign in: Dynamic client registration failed with status [400].');
});
