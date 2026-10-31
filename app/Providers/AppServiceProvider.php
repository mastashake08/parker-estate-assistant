<?php

namespace App\Providers;

use App\Services\Browser\Browser;
use App\Services\Browser\ChromeBrowser;
use App\Services\Mcp\ClientMcpConnector;
use App\Services\Mcp\McpConnector;
use App\Services\Search\BraveSearch;
use App\Services\Search\DuckDuckGoSearch;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearxngSearch;
use App\Services\Search\TavilySearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(McpConnector::class, ClientMcpConnector::class);

        $this->app->bind(Browser::class, fn () => new ChromeBrowser(
            endpoint: config('assistant.browser.endpoint'),
            binary: config('assistant.browser.binary'),
            timeout: (int) config('assistant.browser.timeout'),
            userAgent: config('assistant.browser.user_agent'),
        ));

        $this->app->bind(SearchEngine::class, fn () => match ($engine = config('assistant.web.search_engine')) {
            'duckduckgo' => new DuckDuckGoSearch($this->app->make(Browser::class), (string) config('assistant.web.search_url')),
            'searxng' => new SearxngSearch(config('assistant.web.searxng_url')),
            'brave' => new BraveSearch(config('assistant.web.brave_api_key')),
            'tavily' => new TavilySearch(config('assistant.web.tavily_api_key')),
            default => throw new InvalidArgumentException("Unknown web search engine [{$engine}]. Use duckduckgo, searxng, brave or tavily."),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Routines run from the scheduler, so `composer run dev` starts it alongside the queue.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('schedule:work', 'schedule');
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
