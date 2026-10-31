<?php

namespace App\Providers;

use App\Services\Browser\Browser;
use App\Services\Browser\ChromeBrowser;
use App\Services\Mcp\ClientMcpConnector;
use App\Services\Mcp\McpConnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
