<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Writes the Markdown links the web tools return, and reads them back so a
 * reply can list the pages it used. web_search writes "1. [Title](url)" for
 * each result, and web_fetch starts with "Source: [Title](url)".
 */
class WebSources
{
    /**
     * The tools whose results link to web pages, by the name the model calls them.
     */
    protected const TOOLS = ['WebSearch', 'WebFetch'];

    /**
     * Write a Markdown link to a page, titled with its URL when it has no title.
     *
     * Spaces and parentheses in the URL are percent-encoded so the link stays
     * one Markdown token; the encoded URL opens the same page.
     */
    public function link(string $title, string $url): string
    {
        $text = Str::squish($title) ?: $url;

        return '['.addcslashes($text, '\\[]').']('.strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29']).')';
    }

    /**
     * Read the pages a web tool linked to, in order and without repeats.
     *
     * Only the links the tool itself wrote count: a search result's title line,
     * or the first line of a fetched page (never links in the page's text).
     *
     * @return list<array{title: string, url: string}>
     */
    public function fromToolResult(string $tool, string $result): array
    {
        if (! in_array($tool, self::TOOLS, true)) {
            return [];
        }

        $lines = $tool === 'WebFetch'
            ? [Str::before($result, "\n")]
            : explode("\n", $result);

        $sources = [];

        foreach ($lines as $line) {
            if (! preg_match('/^(?:\d+\.|Source:) \[((?:\\\\.|[^\]\\\\])*)\]\((\S+)\)$/', trim($line), $matches)) {
                continue;
            }

            $url = $matches[2];

            if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || isset($sources[$url])) {
                continue;
            }

            $sources[$url] = ['title' => preg_replace('/\\\\(.)/', '$1', $matches[1]), 'url' => $url];
        }

        return array_values($sources);
    }
}
