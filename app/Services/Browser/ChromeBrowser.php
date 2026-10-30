<?php

namespace App\Services\Browser;

use HeadlessChromium\Browser as ChromiumBrowser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use Throwable;

/**
 * Renders pages with Chrome, either by launching a local Chrome binary or by
 * connecting to a remote headless Chrome (such as the "chrome" Sail service).
 */
class ChromeBrowser implements Browser
{
    /**
     * Create a new Chrome browser instance.
     */
    public function __construct(
        protected ?string $endpoint = null,
        protected ?string $binary = null,
        protected int $timeout = 30,
        protected ?string $userAgent = null,
    ) {}

    /**
     * Load the URL in a headless browser, run its JavaScript, and capture the result.
     */
    public function render(string $url): RenderedPage
    {
        try {
            $browser = $this->open();
        } catch (Throwable $exception) {
            throw new BrowserException('Could not start the headless browser: '.$exception->getMessage(), previous: $exception);
        }

        try {
            $page = $browser->createPage();

            if ($this->userAgent !== null) {
                $page->setUserAgent($this->userAgent);
            }

            $page->navigate($url)->waitForNavigation(Page::LOAD, $this->timeout * 1000);

            return new RenderedPage(
                url: (string) $page->getCurrentUrl(),
                title: (string) $page->evaluate('document.title')->getReturnValue(),
                html: $page->getHtml(),
                text: (string) $page->evaluate('document.body ? document.body.innerText : ""')->getReturnValue(),
            );
        } catch (Throwable $exception) {
            throw new BrowserException($exception->getMessage() ?: class_basename($exception), previous: $exception);
        } finally {
            $this->closeQuietly($browser);
        }
    }

    /**
     * Connect to the remote browser, or launch a local one.
     */
    protected function open(): ChromiumBrowser
    {
        $options = ['sendSyncDefaultTimeout' => $this->timeout * 1000];

        if (filled($this->endpoint)) {
            return BrowserFactory::connectToBrowser($this->endpointUri((string) $this->endpoint), $options);
        }

        return (new BrowserFactory(filled($this->binary) ? $this->binary : null))->createBrowser([
            ...$options,
            'headless' => true,
            'noSandbox' => true,
            'startupTimeout' => $this->timeout,
        ]);
    }

    /**
     * The WebSocket client needs a path, so "ws://chrome:3000" becomes "ws://chrome:3000/".
     */
    protected function endpointUri(string $endpoint): string
    {
        return parse_url($endpoint, PHP_URL_PATH) ? $endpoint : preg_replace('#^([a-z]+://[^/?]+)#i', '$1/', $endpoint);
    }

    /**
     * Close the browser, ignoring errors from one that has already gone away.
     */
    protected function closeQuietly(ChromiumBrowser $browser): void
    {
        try {
            $browser->close();
        } catch (Throwable) {
            //
        }
    }
}
