<?php

namespace App\Services\Browser;

interface Browser
{
    /**
     * Load the URL in a headless browser, run its JavaScript, and capture the result.
     *
     * @throws BrowserException
     */
    public function render(string $url): RenderedPage;
}
