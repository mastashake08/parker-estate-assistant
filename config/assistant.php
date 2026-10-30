<?php

use App\Ai\Tools\CurrentDateTime;
use App\Ai\Tools\WebFetch;
use App\Ai\Tools\WebSearch;

return [

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | The AI provider (from config/ai.php) and model the estate assistant
    | talks to. Any model you have pulled into Ollama may be used here,
    | but it should support tool calling to make use of MCP servers.
    |
    */

    'provider' => env('ASSISTANT_PROVIDER', env('AI_PROVIDER', 'ollama')),

    'model' => env('ASSISTANT_MODEL', 'llama3.1'),

    'instructions' => env('ASSISTANT_INSTRUCTIONS', 'You are Parker Estate Assistant, a helpful personal assistant. Use the tools and skills available to you when they are relevant, and say so when you do not know something.'),

    /*
    |--------------------------------------------------------------------------
    | Conversation Memory
    |--------------------------------------------------------------------------
    |
    | The number of previous messages from the current conversation that are
    | sent back to the model with every prompt. Small local models have
    | small context windows, so keep this modest.
    |
    */

    'memory_messages' => (int) env('ASSISTANT_MEMORY_MESSAGES', 50),

    /*
    |--------------------------------------------------------------------------
    | Built-in Tools
    |--------------------------------------------------------------------------
    |
    | Tool classes that ship with the application. Each user may switch any
    | of them off from the tools settings page.
    |
    */

    'tools' => [
        CurrentDateTime::class,
        WebSearch::class,
        WebFetch::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Tools
    |--------------------------------------------------------------------------
    |
    | The web search and web fetch tools render pages in headless Chrome so
    | JavaScript-heavy sites work. Set BROWSER_ENDPOINT to the WebSocket URL
    | of a remote Chrome (the "chrome" Sail service), or leave it empty to
    | launch the Chrome installed on this machine (BROWSER_CHROME_BINARY if needed).
    |
    | The search URL receives the URL-encoded query in place of "%s" and
    | must return DuckDuckGo's HTML results markup.
    |
    */

    'browser' => [
        'endpoint' => env('BROWSER_ENDPOINT'),
        'binary' => env('BROWSER_CHROME_BINARY'),
        'timeout' => (int) env('BROWSER_TIMEOUT', 30),
        'user_agent' => env('BROWSER_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
    ],

    'web' => [
        'search_url' => env('WEB_SEARCH_URL', 'https://html.duckduckgo.com/html/?q=%s'),
        'search_results' => (int) env('WEB_SEARCH_RESULTS', 5),
        'max_characters' => (int) env('WEB_FETCH_MAX_CHARACTERS', 20000),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Servers
    |--------------------------------------------------------------------------
    |
    | Local (stdio) MCP servers run a command on the machine hosting this
    | app. Disable them if anyone other than you can sign in.
    |
    */

    'mcp' => [
        'stdio_enabled' => (bool) env('ASSISTANT_MCP_STDIO', true),
        'timeout' => (float) env('ASSISTANT_MCP_TIMEOUT', 30),
    ],

];
