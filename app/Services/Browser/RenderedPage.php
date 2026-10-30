<?php

namespace App\Services\Browser;

final readonly class RenderedPage
{
    /**
     * Create a new rendered page instance.
     *
     * @param  string  $url  The URL the browser ended up on after any redirects.
     * @param  string  $text  The visible text of the page, as a reader would see it.
     */
    public function __construct(
        public string $url,
        public string $title,
        public string $html,
        public string $text,
    ) {}
}
