<?php

use App\Ai\Tools\CurrentDateTime;

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
