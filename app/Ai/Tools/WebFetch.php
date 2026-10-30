<?php

namespace App\Ai\Tools;

use App\Services\WebFetchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class WebFetch implements Tool
{
    public function __construct(protected WebFetchService $fetcher) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Open a web page in a headless browser and read its text, including content rendered by JavaScript. Use it to read a URL from search results or one the user gives you.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->fetcher->fetch($request->string('url')->trim()->toString());
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('The full http(s) URL of the page to read.')->required(),
        ];
    }
}
