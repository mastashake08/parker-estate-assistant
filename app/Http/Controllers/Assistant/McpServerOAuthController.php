<?php

namespace App\Http\Controllers\Assistant;

use App\Enums\McpTransport;
use App\Http\Controllers\Controller;
use App\Models\McpServer;
use App\Services\Mcp\McpOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Laravel\Mcp\Client\Exceptions\OAuthException;
use Symfony\Component\HttpFoundation\Response;

class McpServerOAuthController extends Controller
{
    public function __construct(protected McpOAuthService $oauth) {}

    /**
     * Send the user to the server's sign-in page.
     */
    public function store(McpServer $mcpServer): Response
    {
        $this->authorizeSignIn($mcpServer);

        try {
            return Inertia::location($this->oauth->authorizationUrl($mcpServer));
        } catch (OAuthException $exception) {
            return $this->failed($exception);
        }
    }

    /**
     * Finish signing in when the server's sign-in page sends the user back.
     */
    public function callback(McpServer $mcpServer): RedirectResponse
    {
        $this->authorizeSignIn($mcpServer);

        try {
            $this->oauth->completeSignIn($mcpServer);
        } catch (OAuthException $exception) {
            return $this->failed($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed in to :name.', ['name' => $mcpServer->name])]);

        return to_route('mcp-servers.index');
    }

    /**
     * Sign out of the server.
     */
    public function destroy(McpServer $mcpServer): RedirectResponse
    {
        $this->authorizeSignIn($mcpServer);

        $this->oauth->signOut($mcpServer);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out of :name.', ['name' => $mcpServer->name])]);

        return to_route('mcp-servers.index');
    }

    /**
     * Only the owner can sign in, and only to remote servers.
     */
    protected function authorizeSignIn(McpServer $mcpServer): void
    {
        Gate::authorize('update', $mcpServer);

        abort_unless($mcpServer->transport === McpTransport::Http, 404);
    }

    /**
     * Return to the server list with the reason signing in failed.
     *
     * The callback address is built from the address the app was opened at,
     * and providers usually reject plain http callbacks other than localhost.
     */
    protected function failed(OAuthException $exception): RedirectResponse
    {
        $message = __('Could not sign in: :reason', ['reason' => $exception->getMessage()]);

        if (! request()->secure() && ! in_array(request()->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)) {
            $message .= ' '.__('Most sign-in providers only accept https addresses or localhost, so open the app at http://localhost and sign in from there.');
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('mcp-servers.index');
    }
}
