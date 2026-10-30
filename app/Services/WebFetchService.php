<?php

namespace App\Services;

use App\Services\Browser\Browser;
use App\Services\Browser\BrowserException;
use App\Services\Browser\PublicUrl;
use Illuminate\Support\Str;

class WebFetchService
{
    public function __construct(
        protected Browser $browser,
        protected WebSources $sources,
    ) {}

    /**
     * Render a public web page and describe it as text for the model.
     */
    public function fetch(string $url): string
    {
        if (! PublicUrl::check($url)) {
            return "Refusing to fetch [{$url}]: only public http(s) addresses are allowed.";
        }

        try {
            $page = $this->browser->render($url);
        } catch (BrowserException $exception) {
            return "Could not fetch [{$url}]: {$exception->getMessage()}";
        }

        if (! PublicUrl::check($page->url)) {
            return "Refusing to fetch [{$url}]: it redirected to a non-public address.";
        }

        return 'Source: '.$this->sources->link($page->title, $page->url)."\n\n".$this->truncate(trim($page->text));
    }

    /**
     * Keep the page text within the model's context budget.
     */
    protected function truncate(string $text): string
    {
        $limit = (int) config('assistant.web.max_characters');
        $length = Str::length($text);

        if ($length <= $limit) {
            return $text;
        }

        return Str::substr($text, 0, $limit)."\n\n[Truncated: showing the first {$limit} of {$length} characters.]";
    }
}
