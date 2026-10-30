<?php

namespace App\Ai\Tools;

use App\Services\WebSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class WebSearch implements Tool
{
    public function __construct(protected WebSearchService $searcher) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search the web and get a list of result titles, URLs and snippets. Use it for current events or facts you are unsure of, then read promising results with the web fetch tool.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->searcher->search(
            $request->string('query')->trim()->toString(),
            $request->filled('limit') ? $request->integer('limit') : null,
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to search for.')->required(),
            'limit' => $schema->integer()->description('How many results to return (1-10).')->min(1)->max(WebSearchService::MAX_RESULTS),
        ];
    }
}
